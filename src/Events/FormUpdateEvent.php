<?php

declare(strict_types=1);

namespace NeuronAI\Form\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Event carrying extracted and validated form data.
 */
class FormUpdateEvent implements Event
{
    /**
     * @param array<string, mixed> $extractedData Data extracted from the conversation
     * @param array<string, array<string>> $validationErrors Validation errors by field
     */
    public function __construct(
        protected array $extractedData = [],
        protected array $validationErrors = []
    ) {
    }

    /**
     * Get the extracted data.
     *
     * @return array<string, mixed>
     */
    public function getExtractedData(): array
    {
        return $this->extractedData;
    }

    /**
     * Get validation errors.
     *
     * @return array<string, array<string>>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Check if there are any validation errors.
     */
    public function hasErrors(): bool
    {
        return $this->validationErrors !== [];
    }
}
