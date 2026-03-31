<?php

declare(strict_types=1);

namespace NeuronAI\Form\Tests;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Form\AIForm;
use NeuronAI\Form\Enums\FormStatus;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Form\Tests\Stubs\User;
use PHPUnit\Framework\TestCase;

class AIFormTest extends TestCase
{
    public function test_form_collects_data_and_completes(): void
    {
        // First response: extraction JSON
        // Second response: conversational confirmation
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name": "Alice"}'),
            new AssistantMessage('Thank you! Your form has been submitted successfully.')
        );

        $form = new AIForm(User::class);
        $form->setAiProvider($provider);

        $handler = $form->process(new UserMessage('My name is Alice'));
        $state = $handler->run();

        $this->assertTrue($form->isComplete());
        $this->assertSame('Alice', $state->getCollectedData()->name);
        $this->assertSame(FormStatus::COMPLETE, $state->getStatus());

        // Verify provider was called twice (extraction + response)
        $provider->assertCallCount(2);
    }

    public function test_form_detects_exit_phrases(): void
    {
        $provider = new FakeAIProvider();

        $form = new AIForm(User::class);
        $form->setAiProvider($provider);

        $handler = $form->process(new UserMessage('cancel'));
        $state = $handler->run();

        $this->assertTrue($form->isComplete());
        $this->assertSame(FormStatus::CLOSED, $state->getStatus());

        // Provider should NOT be called when user exits
        $provider->assertNothingSent();
    }

    public function test_form_tracks_missing_fields(): void
    {
        // Response with empty object (no data extracted)
        $provider = new FakeAIProvider(
            new AssistantMessage('{}'),
            new AssistantMessage('What is your name?')
        );

        $form = new AIForm(User::class);
        $form->setAiProvider($provider);

        $handler = $form->process(new UserMessage('Hello'));
        $state = $handler->run();

        $this->assertFalse($form->isComplete());
        $this->assertSame(FormStatus::INCOMPLETE, $state->getStatus());
        $this->assertContains('name', $state->getMissingFields());
    }
}
