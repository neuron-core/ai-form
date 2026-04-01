<?php

declare(strict_types=1);

namespace NeuronAI\Form\Nodes;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Form\Events\FormStartEvent;
use NeuronAI\Form\Events\FormUpdateEvent;
use NeuronAI\Form\FormState;
use NeuronAI\Observability\Events\Extracted;
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\Workflow\Node;

use function array_filter;
use function array_merge;
use function get_object_vars;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;

/**
 * Node responsible for extracting structured data from user messages.
 *
 * This node uses the AI provider to extract form data from the conversation
 * based on the JSON schema generated from the form data class.
 */
class ExtractionNode extends Node
{
    use ChatHistoryHelper;

    public function __construct(
        protected AIProviderInterface $provider,
        protected array $schema
    ) {
    }

    /**
     * Extract structured data from user input.
     *
     * @throws InspectorException
     */
    public function __invoke(FormStartEvent $event, FormState $state): FormUpdateEvent
    {
        // Note: User message is already added to chat history in AIForm::process()

        // Build extraction prompt
        $prompt = $this->buildExtractionPrompt($state);

        // Create message for extraction
        $extractionMessage = new UserMessage($prompt);

        $this->emit('inference-start', new InferenceStart($extractionMessage));

        // Call AI to extract data
        $response = $this->provider
            ->systemPrompt($this->getSystemPrompt())
            ->chat($extractionMessage);

        $this->emit('inference-stop', new InferenceStop($extractionMessage, $response));

        // Extract JSON from response
        $this->emit('structured-extracting', new Extracting($response));
        $json = (new JsonExtractor())->getJson($response->getContent());
        $this->emit('structured-extracted', new Extracted($response, $this->schema, $json));

        $extractedData = [];
        if ($json !== null && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $extractedData = $this->filterNullValues($decoded);
            }
        }

        // Merge with existing collected data
        $existingData = $this->objectToArray($state->getCollectedData());
        $mergedData = array_merge($existingData, $extractedData);

        // Store extracted data in state
        $state->setExtractedData($mergedData);

        return new FormUpdateEvent($mergedData);
    }

    /**
     * Build the extraction prompt with schema and context.
     */
    protected function buildExtractionPrompt(FormState $state): string
    {
        $schemaJson = json_encode($this->schema, JSON_PRETTY_PRINT);
        $currentDataJson = json_encode(
            $this->objectToArray($state->getCollectedData()),
            JSON_PRETTY_PRINT
        );

        $chatHistory = $state->getChatHistory();
        $conversationContext = $this->stringifyChatHistory($chatHistory);

        return <<<PROMPT
Your task is to extract information from a conversation and fill up a JSON object.

The JSON must follow this schema:
```json
{$schemaJson}
```

This is the current data already collected:
```json
{$currentDataJson}
```

This is the conversation so far:
{$conversationContext}

Extract any new or updated information from the conversation and return the complete updated JSON object.
Only include fields that have values. Use `null` for fields where no information is available yet.

Updated JSON:
PROMPT;
    }

    /**
     * Get the system prompt for the extraction agent.
     */
    protected function getSystemPrompt(): string
    {
        return "You are a data extraction assistant. Your job is to extract structured information from conversations. ".
               "Return ONLY a valid JSON object. Do not include any explanation or text outside the JSON.";
    }

    /**
     * Convert object to array recursively.
     */
    protected function objectToArray(?object $object): array
    {
        if ($object === null) {
            return [];
        }

        $array = [];
        foreach (get_object_vars($object) as $key => $value) {
            $array[$key] = is_object($value) ? $this->objectToArray($value) : $value;
        }

        return $array;
    }

    /**
     * Filter out null values from the array.
     */
    protected function filterNullValues(array $data): array
    {
        $nullValues = [null, '', 'None', 'null', 'unknown', 'missing', 'N/A', 'n/a'];

        return array_filter($data, fn (mixed $value): bool => !in_array($value, $nullValues, true));
    }

    /**
     * Stringify chat history for the prompt.
     */
    protected function stringifyChatHistory(ChatHistoryInterface $chatHistory): string
    {
        $messages = $chatHistory->getMessages();
        $lines = [];

        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                continue;
            }
            $role = $message->getRole();
            $content = $message->getContent();
            $lines[] = "[{$role}]: {$content}";
        }

        return implode("\n", $lines);
    }
}
