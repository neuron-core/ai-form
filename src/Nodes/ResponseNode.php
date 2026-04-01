<?php

declare(strict_types=1);

namespace NeuronAI\Form\Nodes;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Form\Enums\FormStatus;
use NeuronAI\Form\Events\FormValidatedEvent;
use NeuronAI\Form\FormState;
use NeuronAI\Form\Interrupt\FormInterruptRequest;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Node;
use Closure;

use function call_user_func;
use function get_object_vars;
use function implode;
use function is_object;
use function json_encode;
use function array_map;

use const JSON_PRETTY_PRINT;

/**
 * Node responsible for generating user-facing responses.
 *
 * This node generates conversational responses based on the current form state,
 * asking for missing fields, reporting validation errors, or triggering
 * the confirmation/submission phases.
 */
class ResponseNode extends Node
{
    use ChatHistoryHelper;
    protected ?Closure $submitCallback = null;

    public function __construct(
        protected AIProviderInterface $provider,
        protected bool $requireConfirmation = false,
        ?callable $submitCallback = null
    ) {
        $this->submitCallback = $submitCallback !== null ? Closure::fromCallable($submitCallback) : null;
    }

    /**
     * Generate a response based on the current form state.
     *
     * @throws InspectorException
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function __invoke(FormValidatedEvent $event, FormState $state): StopEvent
    {
        // Check if form is complete
        if ($state->isComplete()) {
            return $this->handleCompleteForm($state);
        }

        // Generate response asking for more information
        return $this->generateInformationRequest($state);
    }

    /**
     * Handle a complete form - either confirm or submit.
     *
     * @throws InspectorException
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function handleCompleteForm(FormState $state): StopEvent
    {
        $collectedData = $state->getCollectedData();

        if ($this->requireConfirmation && !$state->getStatus()->isWaitingConfirmation()) {
            // Need confirmation - set status and generate confirmation message
            $state->setStatus(FormStatus::WAIT_CONFIRM);

            // Generate confirmation message
            $placeholderMessage = new UserMessage('Generate confirmation');
            $this->emit('inference-start', new InferenceStart($placeholderMessage));

            $response = $this->generateConfirmationMessage($state);

            $this->emit('inference-end', new InferenceStop($placeholderMessage, $response));

            // Add response to chat history
            $this->addToChatHistory($state, $response);

            // Trigger interrupt for confirmation - this throws WorkflowInterrupt
            $this->interrupt(new FormInterruptRequest(
                $response->getContent(),
                $collectedData,
                FormStatus::WAIT_CONFIRM
            ));

            // This line is never reached if interrupt is triggered
            return new StopEvent();
        }

        // No confirmation required or already confirmed - submit
        $state->setStatus(FormStatus::COMPLETE);

        // Execute submit callback if provided
        if ($this->submitCallback instanceof Closure && $collectedData !== null) {
            call_user_func($this->submitCallback, $collectedData);
        }

        $state->setSubmittedData($collectedData);

        $placeholderMessage = new UserMessage('Generate submission');
        $this->emit('form-submit-start', new InferenceStart($placeholderMessage));

        $response = $this->generateSubmissionMessage($state);

        $this->emit('form-submit-stop', new InferenceStop($placeholderMessage, $response));

        // Add response to chat history
        $this->addToChatHistory($state, $response);

        return new StopEvent($collectedData);
    }

    /**
     * Generate a response asking for more information.
     *
     * @throws InspectorException
     */
    protected function generateInformationRequest(FormState $state): StopEvent
    {
        $prompt = $this->buildInformationRequestPrompt($state);

        $requestMessage = new UserMessage($prompt);

        $this->emit('inference-start', new InferenceStart($requestMessage));

        $response = $this->provider
            ->systemPrompt($this->getSystemPrompt())
            ->chat($requestMessage);

        $this->emit('inference-stop', new InferenceStop($requestMessage, $response));

        // Add response to chat history
        $this->addToChatHistory($state, $response);

        return new StopEvent();
    }

    /**
     * Build the prompt for requesting more information.
     */
    protected function buildInformationRequestPrompt(FormState $state): string
    {
        $missingFields = $state->getMissingFields();
        $validationErrors = $state->getValidationErrors();
        $completionPercentage = $state->getCompletionPercentage();

        $prompt = "Current form completion: {$completionPercentage}%\n\n";

        if ($missingFields !== []) {
            $prompt .= "Missing fields: " . implode(', ', $missingFields) . "\n\n";
        }

        if ($validationErrors !== []) {
            $prompt .= "Validation errors:\n";
            foreach ($validationErrors as $field => $errors) {
                $prompt .= "- {$field}: " . implode(', ', $errors) . "\n";
            }
            $prompt .= "\n";
        }

        return $prompt . "\nBased on the conversation context and the missing fields above, " .
                "generate a friendly, conversational message asking the user for the next piece of information. " .
                "Ask for one or two fields at a time. If there are validation errors, explain them clearly.";
    }

    /**
     * Generate the confirmation message for the user.
     */
    protected function generateConfirmationMessage(FormState $state): Message
    {
        $data = $state->getCollectedData();
        $dataJson = json_encode($this->objectToArray($data), JSON_PRETTY_PRINT);

        $prompt = "Generate a confirmation message for the following data. ".
                  "List all the collected information in a clear, readable format and ask the user to confirm.\n\n".
                  "Collected data:\n```json\n{$dataJson}\n```\n\n".
                  "Ask: 'Is this information correct? Please confirm or let me know what needs to be changed.'";

        $requestMessage = new UserMessage($prompt);

        $this->emit('inference-start', new InferenceStart($requestMessage));
        $response = $this->provider
            ->systemPrompt($this->getSystemPrompt())
            ->chat($requestMessage);
        $this->emit('inference-stop', new InferenceStop($requestMessage, $response));

        return $response;
    }

    /**
     * Generate the submission success message.
     */
    protected function generateSubmissionMessage(FormState $state): Message
    {
        $prompt = "Generate a brief, friendly confirmation message that the form has been successfully submitted. ".
                  "Thank the user for providing the information.";

        $requestMessage = new UserMessage($prompt);

        $this->emit('inference-start', new InferenceStart($requestMessage));
        $response = $this->provider
            ->systemPrompt($this->getSystemPrompt())
            ->chat($requestMessage);
        $this->emit('inference-stop', new InferenceStop($requestMessage, $response));

        return $response;
    }

    /**
     * Get the system prompt for the response generation agent.
     */
    protected function getSystemPrompt(): string
    {
        return "You are a friendly, conversational form assistant. ".
               "Your job is to collect information from users through natural conversation. ".
               "Be helpful, patient, and clear. ".
               "Ask for information in a conversational way, not like filling out a form.";
    }

    /**
     * Convert object to array recursively.
     */
    protected function objectToArray(?object $object): array
    {
        if ($object === null) {
            return [];
        }

        return array_map(fn (mixed $value): mixed => is_object($value) ? $this->objectToArray($value) : $value, get_object_vars($object));
    }
}
