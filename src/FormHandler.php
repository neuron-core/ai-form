<?php

declare(strict_types=1);

namespace NeuronAI\Form;

use NeuronAI\Form\Enums\FormStatus;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowHandler;

/**
 * Handler for form execution.
 *
 * Extends WorkflowHandler to provide form-specific convenience methods
 * for accessing form state, collected data, and completion status.
 *
 * @method FormState run()
 */
class FormHandler extends WorkflowHandler
{
    protected ?FormState $cachedResult = null;

    public function __construct(
        protected AIForm $form,
        protected ?InterruptRequest $resumeRequest = null
    ) {
        parent::__construct($form, $resumeRequest);
    }

    /**
     * Execute the form workflow and get the final state.
     */
    public function run(): FormState
    {
        if ($this->cachedResult instanceof FormState) {
            return $this->cachedResult;
        }

        // If the form is already closed (e.g., via exit phrase), skip workflow execution
        $formState = $this->form->resolveState();
        if ($formState->getStatus()->isClosed()) {
            $this->cachedResult = $formState;
            return $this->cachedResult;
        }

        $state = parent::run();

        if ($state instanceof FormState) {
            $this->cachedResult = $state;
        }

        return $this->cachedResult;
    }

    /**
     * Get the form status.
     */
    public function getStatus(): FormStatus
    {
        return $this->run()->getStatus();
    }

    /**
     * Get the collected form data.
     */
    public function getData(): ?object
    {
        return $this->run()->getCollectedData();
    }

    /**
     * Get the submitted form data (available after submission).
     */
    public function getSubmittedData(): ?object
    {
        return $this->run()->getSubmittedData();
    }

    /**
     * Get the form completion percentage.
     */
    public function getCompletionPercentage(): int
    {
        return $this->run()->getCompletionPercentage();
    }

    /**
     * Get missing fields.
     *
     * @return string[]
     */
    public function getMissingFields(): array
    {
        return $this->run()->getMissingFields();
    }

    /**
     * Get validation errors.
     *
     * @return array<string, string[]>
     */
    public function getValidationErrors(): array
    {
        return $this->run()->getValidationErrors();
    }

    /**
     * Check if the form is complete.
     */
    public function isComplete(): bool
    {
        return $this->run()->isComplete();
    }

    /**
     * Get the last AI response from chat history.
     */
    public function getLastResponse(): ?string
    {
        $chatHistory = $this->run()->getChatHistory();
        $lastMessage = $chatHistory->getLastMessage();

        if ($lastMessage === false) {
            return null;
        }

        return $lastMessage->getContent();
    }

    /**
     * Get the form instance.
     */
    public function getForm(): AIForm
    {
        return $this->form;
    }
}
