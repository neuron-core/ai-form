<?php

declare(strict_types=1);

namespace NeuronAI\Form\Interrupt;

use NeuronAI\Form\Enums\FormStatus;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Interrupt request for form confirmation phase.
 *
 * This interrupt is thrown when a form has collected all required data
 * and needs user confirmation before submission.
 */
class FormInterruptRequest extends InterruptRequest
{
    /**
     * @param string $message Human-readable message asking for confirmation
     * @param object $data The collected form data for user review
     * @param FormStatus $status Current form status
     */
    public function __construct(
        string $message,
        protected object $data,
        protected FormStatus $status = FormStatus::WAIT_CONFIRM
    ) {
        parent::__construct($message);
    }

    /**
     * Get the form data for review.
     */
    public function getData(): object
    {
        return $this->data;
    }

    /**
     * Get the current form status.
     */
    public function getStatus(): FormStatus
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'data' => $this->data,
            'status' => $this->status->value,
        ];
    }
}
