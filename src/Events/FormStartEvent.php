<?php

declare(strict_types=1);

namespace NeuronAI\Form\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

/**
 * Event that starts the form processing workflow.
 */
class FormStartEvent implements Event
{
    public function __construct(
        protected Message $message
    ) {
    }

    /**
     * Get the user message that triggered this form turn.
     */
    public function getMessage(): Message
    {
        return $this->message;
    }
}
