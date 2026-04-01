<?php

declare(strict_types=1);

namespace NeuronAI\Form\Tests;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Form\AIForm;
use NeuronAI\Form\Enums\FormStatus;
use NeuronAI\Form\Tests\Stubs\RegistrationData;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\TestCase;

class RegistrationFormTest extends TestCase
{
    public function test_form_completes_with_all_required_fields(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name": "Jane Smith", "email": "jane@example.com"}'),
            new AssistantMessage('Thank you for providing all information!')
        );

        $form = AIForm::make();
        $form->setFormDataClass(RegistrationData::class);
        $form->setAiProvider($provider);

        $handler = $form->process(new UserMessage('I am Jane Smith, email jane@example.com'));
        $state = $handler->run();

        $this->assertTrue($form->isComplete());
        $this->assertSame(FormStatus::COMPLETE, $state->getStatus());
        $this->assertSame('Jane Smith', $state->getCollectedData()->name);
        $this->assertSame('jane@example.com', $state->getCollectedData()->email);
        $this->assertEmpty($state->getMissingFields());
    }

    public function test_form_awaits_confirmation_when_required(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name": "Jane Smith", "email": "jane@example.com"}'),
            new AssistantMessage('Please confirm your information.')
        );

        $form = AIForm::make();
        $form->setFormDataClass(RegistrationData::class);
        $form->setAiProvider($provider);
        $form->requireConfirmation();

        $handler = $form->process(new UserMessage('I am Jane Smith, email jane@example.com'));

        // When confirmation is required, the workflow throws WorkflowInterrupt
        $this->expectException(WorkflowInterrupt::class);
        $handler->run();
    }

    public function test_form_validates_email_format(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name": "Bob", "email": "invalid-email"}'),
            new AssistantMessage('The email address is not valid.')
        );

        $form = AIForm::make();
        $form->setFormDataClass(RegistrationData::class);
        $form->setAiProvider($provider);

        $handler = $form->process(new UserMessage('Name: Bob, email: invalid-email'));
        $state = $handler->run();

        $this->assertFalse($form->isComplete());
        $this->assertSame(FormStatus::INCOMPLETE, $state->getStatus());
        $this->assertNotEmpty($state->getValidationErrors());
    }

    public function test_form_accepts_optional_fields(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name": "Alice", "email": "alice@company.com", "phone": "+1234567890", "company": "Acme Corp"}'),
            new AssistantMessage('Thank you!')
        );

        $form = AIForm::make();
        $form->setFormDataClass(RegistrationData::class);
        $form->setAiProvider($provider);

        $handler = $form->process(new UserMessage('Alice from Acme Corp, alice@company.com, phone +1234567890'));
        $state = $handler->run();

        $this->assertTrue($form->isComplete());
        $this->assertSame(FormStatus::COMPLETE, $state->getStatus());
        $this->assertSame('Alice', $state->getCollectedData()->name);
        $this->assertSame('alice@company.com', $state->getCollectedData()->email);
        $this->assertSame('+1234567890', $state->getCollectedData()->phone);
        $this->assertSame('Acme Corp', $state->getCollectedData()->company);
    }

    public function test_form_can_be_closed_at_any_time(): void
    {
        $provider = new FakeAIProvider();

        $form = AIForm::make();
        $form->setFormDataClass(RegistrationData::class);
        $form->setAiProvider($provider);

        $handler = $form->process(new UserMessage('stop'));
        $state = $handler->run();

        $this->assertTrue($form->isComplete());
        $this->assertSame(FormStatus::CLOSED, $state->getStatus());
        $provider->assertNothingSent();
    }

    public function test_form_executes_submit_callback(): void
    {
        $submittedData = null;

        $provider = new FakeAIProvider(
            new AssistantMessage('{"name": "Eve", "email": "eve@test.com"}'),
            new AssistantMessage('Form submitted!')
        );

        $form = AIForm::make();
        $form->setFormDataClass(RegistrationData::class);
        $form->setAiProvider($provider);
        $form->onSubmit(function (object $data) use (&$submittedData): void {
            $submittedData = $data;
        });

        $handler = $form->process(new UserMessage('Eve, eve@test.com'));
        $handler->run();

        $this->assertTrue($form->isComplete());
        $this->assertNotNull($submittedData);
        $this->assertSame('Eve', $submittedData->name);
        $this->assertSame('eve@test.com', $submittedData->email);
    }

    public function test_form_handles_custom_exit_phrases(): void
    {
        $provider = new FakeAIProvider();

        $form = AIForm::make();
        $form->setFormDataClass(RegistrationData::class);
        $form->setAiProvider($provider);
        $form->setExitPhrases(['abort', 'terminate']);

        $handler = $form->process(new UserMessage('abort mission'));
        $state = $handler->run();

        $this->assertTrue($form->isComplete());
        $this->assertSame(FormStatus::CLOSED, $state->getStatus());
        $provider->assertNothingSent();
    }

    public function test_form_status_transitions_from_incomplete_to_complete(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name": "Test User", "email": "test@example.com"}'),
            new AssistantMessage('Done!')
        );

        $form = AIForm::make();
        $form->setFormDataClass(RegistrationData::class);
        $form->setAiProvider($provider);

        // Initial status should be INCOMPLETE
        $this->assertSame(FormStatus::INCOMPLETE, $form->getStatus());

        $handler = $form->process(new UserMessage('Test User, test@example.com'));
        $state = $handler->run();

        // After processing, status should be COMPLETE
        $this->assertSame(FormStatus::COMPLETE, $state->getStatus());
    }
}
