<?php

declare(strict_types=1);

namespace NeuronAI\Form\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Event triggered when form data is ready for user confirmation.
 */
class FormConfirmEvent implements Event
{
    public function __construct(
        protected object $data
    ) {
    }

    /**
     * Get the form data awaiting confirmation.
     */
    public function getData(): object
    {
        return $this->data;
    }
}
