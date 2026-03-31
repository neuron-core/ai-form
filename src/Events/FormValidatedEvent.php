<?php

declare(strict_types=1);

namespace NeuronAI\Form\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Event carrying validated form data, ready for response generation.
 */
class FormValidatedEvent implements Event
{
    /**
     * @param array<string, mixed> $extractedData Data extracted and validated from the conversation
     */
    public function __construct(
        protected array $extractedData = []
    ) {
    }

    /**
     * Get the validated data.
     *
     * @return array<string, mixed>
     */
    public function getExtractedData(): array
    {
        return $this->extractedData;
    }
}
