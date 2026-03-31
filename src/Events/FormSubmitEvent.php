<?php

declare(strict_types=1);

namespace NeuronAI\Form\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Event triggered when form is ready to be submitted.
 */
class FormSubmitEvent implements Event
{
    public function __construct(
        protected object $data
    ) {
    }

    /**
     * Get the form data to be submitted.
     */
    public function getData(): object
    {
        return $this->data;
    }
}
