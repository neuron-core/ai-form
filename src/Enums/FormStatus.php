<?php

declare(strict_types=1);

namespace NeuronAI\Form\Enums;

/**
 * Represents the status of a form in the data collection process.
 */
enum FormStatus: string
{
    case INCOMPLETE = 'incomplete';
    case WAIT_CONFIRM = 'wait_confirm';
    case COMPLETE = 'complete';
    case CLOSED = 'closed';

    /**
     * Check if the form is still collecting data.
     */
    public function isIncomplete(): bool
    {
        return $this === self::INCOMPLETE;
    }

    /**
     * Check if the form is waiting for user confirmation.
     */
    public function isWaitingConfirmation(): bool
    {
        return $this === self::WAIT_CONFIRM;
    }

    /**
     * Check if the form has been completed and submitted.
     */
    public function isComplete(): bool
    {
        return $this === self::COMPLETE;
    }

    /**
     * Check if the form has been closed (cancelled or finished).
     */
    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }
}
