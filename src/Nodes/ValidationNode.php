<?php

declare(strict_types=1);

namespace NeuronAI\Form\Nodes;

use NeuronAI\Form\Events\FormUpdateEvent;
use NeuronAI\Form\Events\FormValidatedEvent;
use NeuronAI\Form\FormState;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Validation\Validator;
use NeuronAI\Workflow\Node;
use ReflectionClass;
use ReflectionProperty;
use Throwable;
use ReflectionException;

use function array_diff;
use function array_keys;
use function array_values;
use function explode;
use function json_encode;
use function str_contains;
use function trim;

/**
 * Node responsible for validating extracted form data.
 *
 * This node validates the extracted data against the form data class
 * using PHP validation attributes and updates the form state accordingly.
 */
class ValidationNode extends Node
{
    public function __construct(
        protected string $formDataClass
    ) {
    }

    /**
     * Validate the extracted data and update form state.
     *
     * @throws ReflectionException
     */
    public function __invoke(FormUpdateEvent $event, FormState $state): FormValidatedEvent
    {
        $extractedData = $event->getExtractedData();

        // Clear previous validation errors
        $state->clearValidationErrors();

        // Try to deserialize the data into the form class
        try {
            $jsonData = json_encode($extractedData);
            $object = Deserializer::make()->fromJson($jsonData, $this->formDataClass);

            // Validate the object using PHP attributes
            $violations = Validator::validate($object);

            if ($violations === []) {
                // Validation passed - update collected data and fields
                $state->setCollectedData($object);
                $this->updateCollectedFields($state, $extractedData);
                $this->updateMissingFields($state);
            } else {
                // Validation failed - store errors
                $state->setValidationErrors($this->formatViolations($violations));
                $this->updateMissingFields($state);
            }
        } catch (Throwable $e) {
            // Deserialization failed - treat as validation error
            $state->addValidationError('_deserialize', $e->getMessage());
            $this->updateMissingFields($state);
        }

        return new FormValidatedEvent(
            $extractedData
        );
    }

    /**
     * Update the collected fields based on extracted data.
     */
    protected function updateCollectedFields(FormState $state, array $extractedData): void
    {
        foreach (array_keys($extractedData) as $field) {
            if (!empty($extractedData[$field])) {
                $state->addCollectedField($field);
            }
        }
    }

    /**
     * Update the missing fields based on form class requirements.
     *
     * @throws ReflectionException
     */
    protected function updateMissingFields(FormState $state): void
    {
        $requiredFields = $this->getRequiredFields();
        $collectedFields = $state->getCollectedFields();

        $missingFields = array_diff($requiredFields, $collectedFields);
        $state->setMissingFields(array_values($missingFields));
    }

    /**
     * Get the list of required fields from the form class.
     *
     * @return string[]
     * @throws ReflectionException
     */
    protected function getRequiredFields(): array
    {
        $required = [];
        $reflection = new ReflectionClass($this->formDataClass);

        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $type = $property->getType();

            // A field is required if it's not nullable and has no default value
            $isNullable = $type?->allowsNull() ?? true;
            $hasDefault = $property->hasDefaultValue();

            if (!$isNullable && !$hasDefault) {
                $required[] = $property->getName();
            }
        }

        return $required;
    }

    /**
     * Format validation violations into field-based errors.
     *
     * @param string[] $violations
     * @return array<string, string[]>
     */
    protected function formatViolations(array $violations): array
    {
        $errors = [];

        foreach ($violations as $violation) {
            // Try to extract field name from violation message
            // Format is typically "fieldname: message"
            if (str_contains($violation, ':')) {
                [$field, $message] = explode(':', $violation, 2);
                $field = trim($field);
                $message = trim($message);

                if (!isset($errors[$field])) {
                    $errors[$field] = [];
                }
                $errors[$field][] = $message;
            } else {
                // Generic error
                if (!isset($errors['_general'])) {
                    $errors['_general'] = [];
                }
                $errors['_general'][] = $violation;
            }
        }

        return $errors;
    }
}
