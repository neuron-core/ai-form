<?php

declare(strict_types=1);

namespace NeuronAI\Form;

use NeuronAI\Agent\AgentState;
use NeuronAI\Form\Enums\FormStatus;

use function array_keys;
use function count;

/**
 * Form-specific state that tracks data collection progress.
 */
class FormState extends AgentState
{
    /**
     * The PHP class name for the form data structure.
     */
    protected string $formDataClass;

    /**
     * The collected form data as an object.
     */
    protected ?object $collectedData = null;

    /**
     * Fields that have been successfully collected and validated.
     *
     * @var array<string, bool>
     */
    protected array $collectedFields = [];

    /**
     * Fields that are still missing (not yet provided).
     *
     * @var string[]
     */
    protected array $missingFields = [];

    /**
     * Validation errors by field name.
     *
     * @var array<string, string[]>
     */
    protected array $validationErrors = [];

    /**
     * Current form status in the state machine.
     */
    protected FormStatus $status = FormStatus::INCOMPLETE;

    /**
     * The final submitted data after successful submission.
     */
    protected ?object $submittedData = null;

    /**
     * Raw extracted data from the current turn.
     *
     * @var array<string, mixed>
     */
    protected array $extractedData = [];

    /**
     * Get the form data class name.
     */
    public function getFormDataClass(): string
    {
        return $this->formDataClass;
    }

    /**
     * Set the form data class name.
     */
    public function setFormDataClass(string $class): self
    {
        $this->formDataClass = $class;
        return $this;
    }

    /**
     * Get the collected form data object.
     */
    public function getCollectedData(): ?object
    {
        return $this->collectedData;
    }

    /**
     * Set the collected form data object.
     */
    public function setCollectedData(object $data): self
    {
        $this->collectedData = $data;
        return $this;
    }

    /**
     * Get the list of collected field names.
     *
     * @return string[]
     */
    public function getCollectedFields(): array
    {
        return array_keys($this->collectedFields);
    }

    /**
     * Check if a field has been collected.
     */
    public function hasCollectedField(string $field): bool
    {
        return isset($this->collectedFields[$field]);
    }

    /**
     * Mark a field as collected.
     */
    public function addCollectedField(string $field): self
    {
        $this->collectedFields[$field] = true;
        return $this;
    }

    /**
     * Mark multiple fields as collected.
     *
     * @param string[] $fields
     */
    public function addCollectedFields(array $fields): self
    {
        foreach ($fields as $field) {
            $this->collectedFields[$field] = true;
        }
        return $this;
    }

    /**
     * Remove a field from collected fields (e.g., after validation error).
     */
    public function removeCollectedField(string $field): self
    {
        unset($this->collectedFields[$field]);
        return $this;
    }

    /**
     * Get the list of missing field names.
     *
     * @return string[]
     */
    public function getMissingFields(): array
    {
        return $this->missingFields;
    }

    /**
     * Set the missing fields list.
     *
     * @param string[] $fields
     */
    public function setMissingFields(array $fields): self
    {
        $this->missingFields = $fields;
        return $this;
    }

    /**
     * Check if there are any missing fields.
     */
    public function hasMissingFields(): bool
    {
        return $this->missingFields !== [];
    }

    /**
     * Get all validation errors.
     *
     * @return array<string, string[]>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get validation errors for a specific field.
     *
     * @return string[]
     */
    public function getValidationErrorsForField(string $field): array
    {
        return $this->validationErrors[$field] ?? [];
    }

    /**
     * Add a validation error for a field.
     */
    public function addValidationError(string $field, string $error): self
    {
        $this->validationErrors[$field][] = $error;
        return $this;
    }

    /**
     * Set all validation errors.
     *
     * @param array<string, string[]> $errors
     */
    public function setValidationErrors(array $errors): self
    {
        $this->validationErrors = $errors;
        return $this;
    }

    /**
     * Clear validation errors for a specific field or all fields.
     */
    public function clearValidationErrors(?string $field = null): self
    {
        if ($field !== null) {
            unset($this->validationErrors[$field]);
        } else {
            $this->validationErrors = [];
        }
        return $this;
    }

    /**
     * Check if there are any validation errors.
     */
    public function hasValidationErrors(): bool
    {
        return $this->validationErrors !== [];
    }

    /**
     * Get the current form status.
     */
    public function getStatus(): FormStatus
    {
        return $this->status;
    }

    /**
     * Set the form status.
     */
    public function setStatus(FormStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Get the submitted data.
     */
    public function getSubmittedData(): ?object
    {
        return $this->submittedData;
    }

    /**
     * Set the submitted data.
     */
    public function setSubmittedData(object $data): self
    {
        $this->submittedData = $data;
        return $this;
    }

    /**
     * Get the extracted data from the current turn.
     *
     * @return array<string, mixed>
     */
    public function getExtractedData(): array
    {
        return $this->extractedData;
    }

    /**
     * Set the extracted data from the current turn.
     *
     * @param array<string, mixed> $data
     */
    public function setExtractedData(array $data): self
    {
        $this->extractedData = $data;
        return $this;
    }

    /**
     * Calculate form completion percentage.
     */
    public function getCompletionPercentage(): int
    {
        $collected = count($this->collectedFields);
        $missing = count($this->missingFields);
        $total = $collected + $missing;

        if ($total === 0) {
            return 0;
        }

        return (int) (($collected / $total) * 100);
    }

    /**
     * Check if all required fields have been collected.
     */
    public function isComplete(): bool
    {
        return $this->missingFields === [] && $this->validationErrors === [];
    }
}
