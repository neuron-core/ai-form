<?php

declare(strict_types=1);

namespace NeuronAI\Form;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Form\Enums\FormStatus;
use NeuronAI\Form\Events\FormStartEvent;
use NeuronAI\Form\Interrupt\FormInterruptRequest;
use NeuronAI\Form\Nodes\ExtractionNode;
use NeuronAI\Form\Nodes\ResponseNode;
use NeuronAI\Form\Nodes\ValidationNode;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;
use Closure;
use RuntimeException;
use ReflectionException;

use function array_diff;
use function array_keys;
use function array_map;
use function array_values;
use function call_user_func;
use function str_contains;
use function strtolower;

/**
 * @method FormState resolveState()
 * @method FormState run()
 */
class AIForm extends Workflow
{
    protected AIProviderInterface $provider;
    protected ?string $formDataClass = null;
    protected bool $requireConfirmation = false;
    protected array $exitPhrases = ['cancel', 'quit', 'exit', 'stop', 'never mind', 'forget it'];
    protected ?Closure $submitCallback = null;
    protected array $schema = [];

    /**
     * @throws ReflectionException
     * @throws WorkflowException
     */
    public function __construct(
        ?string $formDataClass = null
    ) {
        parent::__construct();

        if ($formDataClass !== null) {
            $this->setFormDataClass($formDataClass);
        }
    }

    /**
     * @throws ReflectionException
     */
    public function setFormDataClass(string $class): self
    {
        $this->formDataClass = $class;
        $this->schema = JsonSchema::make()->generate($class);
        return $this;
    }

    public function getFormDataClass(): string
    {
        if ($this->formDataClass === null) {
            throw new RuntimeException('Form data class not configured. Call setFormDataClass() first.');
        }
        return $this->formDataClass;
    }

    public function setAiProvider(AIProviderInterface $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        return $this->provider;
    }

    /**
     * Get the current provider instance.
     */
    protected function resolveProvider(): AIProviderInterface
    {
        return $this->provider ??= $this->provider();
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->resolveState()->setChatHistory($chatHistory);
        return $this;
    }

    public function requireConfirmation(bool $require = true): self
    {
        $this->requireConfirmation = $require;
        return $this;
    }

    public function setExitPhrases(array $phrases): self
    {
        $this->exitPhrases = array_map(strtolower(...), $phrases);
        return $this;
    }

    public function onSubmit(callable $callback): self
    {
        $this->submitCallback = $callback;
        return $this;
    }

    protected function callback(): ?Closure
    {
        return $this->submitCallback;
    }

    protected function resolveCallback(): ?Closure
    {
        return $this->submitCallback ??= $this->callback();
    }

    /**
     * Create the form state instance.
     */
    protected function state(): FormState
    {
        $state = new FormState();
        $state->setChatHistory(new InMemoryChatHistory());

        $state->setFormDataClass($this->getFormDataClass());

        $this->initializeMissingFields($state);

        return $state;
    }

    /**
     * Create the start event for the workflow.
     */
    protected function startEvent(): FormStartEvent
    {
        $lastMessage = $this->resolveState()->getChatHistory()->getLastMessage();
        return new FormStartEvent($lastMessage !== false ? $lastMessage : new UserMessage(''));
    }

    /**
     * Compose the workflow nodes for form processing.
     *
     * @throws ReflectionException
     */
    protected function compose(): void
    {
        if ($this->eventNodeMap !== []) {
            return; // Already composed
        }

        $provider = $this->resolveProvider();
        $formDataClass = $this->getFormDataClass();

        $this->addNodes([
            new ExtractionNode($provider, $formDataClass),
            new ValidationNode($formDataClass),
            new ResponseNode($provider, $this->requireConfirmation, $this->resolveCallback()),
        ]);
    }

    /**
     * Process a user message through the form workflow.
     *
     * @throws ReflectionException
     */
    public function process(Message $message, ?InterruptRequest $interrupt = null): FormHandler
    {
        // Initialize state (lazy)
        $state = $this->resolveState();

        // Add user message to chat history
        $state->getChatHistory()->addMessage($message);

        // Check for exit phrases (before workflow)
        if ($this->detectExit($message->getContent())) {
            $state->setStatus(FormStatus::CLOSED);
            return new FormHandler($this, $interrupt);
        }

        // Handle confirmation response if resuming from interrupt
        if ($interrupt instanceof FormInterruptRequest) {
            $this->handleConfirmationResponse($message, $interrupt);
        }

        // Set start event and compose nodes
        $this->setStartEvent(new FormStartEvent($message));
        $this->compose();

        return new FormHandler($this, $interrupt);
    }

    public function getData(): ?object
    {
        return $this->resolveState()->getCollectedData();
    }

    public function isComplete(): bool
    {
        $status = $this->resolveState()->getStatus();
        if ($status->isComplete()) {
            return true;
        }
        return $status->isClosed();
    }

    public function getStatus(): FormStatus
    {
        return $this->resolveState()->getStatus();
    }

    /**
     * Get the JSON schema for the form data class.
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * Initialize missing fields from the schema.
     */
    protected function initializeMissingFields(FormState $state): void
    {
        $properties = array_keys($this->schema['properties'] ?? []);
        $state->setMissingFields(array_values(array_diff($properties, [])));
    }

    /**
     * Handle user response to a confirmation prompt.
     */
    protected function handleConfirmationResponse(Message $message, FormInterruptRequest $interrupt): void
    {
        $content = strtolower((string) $message->getContent());
        $state = $this->resolveState();

        // Simple confirmation detection
        $confirmPhrases = ['yes', 'confirm', 'correct', 'right', 'ok', 'okay', 'sure', 'yep', 'yeah'];
        $rejectPhrases = ['no', 'cancel', 'wrong', 'incorrect', 'change', 'edit', 'nope'];

        foreach ($confirmPhrases as $phrase) {
            if (str_contains($content, $phrase)) {
                // User confirmed - proceed to submission
                $state->setStatus(FormStatus::COMPLETE);

                // Execute submit callback
                if ($this->resolveCallback() instanceof Closure) {
                    $data = $state->getCollectedData();
                    if ($data !== null) {
                        call_user_func($this->resolveCallback(), $data);
                        $state->setSubmittedData($data);
                    }
                }
                return;
            }
        }

        foreach ($rejectPhrases as $phrase) {
            if (str_contains($content, $phrase)) {
                // User rejected - back to incomplete
                $state->setStatus(FormStatus::INCOMPLETE);
                return;
            }
        }

        // Ambiguous response - stay in wait_confirm
    }

    /**
     * Detect if the user wants to exit the form.
     */
    protected function detectExit(string $content): bool
    {
        $content = strtolower($content);

        foreach ($this->exitPhrases as $phrase) {
            if (str_contains($content, (string) $phrase)) {
                return true;
            }
        }

        return false;
    }
}
