# AIForm - Conversational Data Collection

AIForm is a component for collecting structured data through multi-turn natural language conversations. It uses an AI agent to progressively gather information defined by a structured output class, validating each piece of data along the way.

## How It Works

AIForm operates as a state machine with the following states:

- **INCOMPLETE** - Still collecting data
- **WAIT_CONFIRM** - All data collected, awaiting user confirmation
- **COMPLETE** - Form submitted successfully
- **CLOSED** - Form cancelled by user

The form maintains conversation history, tracks collected fields, missing fields, and validation errors across multiple turns.

## Creating a Custom Form

Extend the `AIForm` class to create your custom form:

```php
<?php

namespace App\Neuron\Forms;

use NeuronAI\Form\AIForm;
use NeuronAI\Form\Enums\FormStatus;
use NeuronAI\Form\FormState;
use NeuronAI\Providers\Anthropic\Anthropic;use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\Email;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

// 1. Define your data structure with validation attributes
class RegistrationData
{
    #[SchemaProperty(description: 'User full name', required: true)]
    #[NotBlank]
    public string $name;

    #[SchemaProperty(description: 'Email address', required: true)]
    #[Email]
    public string $email;

    #[SchemaProperty(description: 'Phone number')]
    public ?string $phone = null;

    #[SchemaProperty(description: 'Company name')]
    public ?string $company = null;
}

// 2. Create your form class extending AIForm
class RegistrationForm extends AIForm
{
    protected string $formDataClass = RegistrationData::class;

    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: 'ANTHROPIC_API_KEY',
            model: 'ANTHROPIC_MODEL',
        );
    }

    /**
     * Handle the submitted form data.
     */
    protected function callback(): mixed
    {
        // $data is an instance of RegistrationData
        // Save to database, send email, call API, etc.
        return function (RegistrationData $data) {
            $this->userService->register($data);
        };
    }
}
```

## Using Your Form in Application Code

### Basic Usage

```php
use App\Forms\RegistrationForm;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;

// Create form instance with AI provider
$form = new RegistrationForm();

// Or use the make() static constructor
$form = RegistrationForm::make()
    ->setAiProvider(new OpenAI('your-api-key'));

// Process user message - Turn 1
$handler = $form->process(new UserMessage("Hi, I'd like to register"));
$state = $handler->run();

echo $state->getStatus()->value;        // 'incomplete'
echo $handler->getLastResponse();        // AI asks for name

// Continue conversation - Turn 2
$handler = $form->process(new UserMessage("My name is John Doe"));
$state = $handler->run();

// Continue - Turn 3
$handler = $form->process(new UserMessage("john@example.com"));
$state = $handler->run();

// Check form progress
echo $state->getCompletionPercentage();  // e.g., 75
print_r($state->getMissingFields());     // ['phone', 'company']
```

### Web Application Example (Controller)

```php
use App\Forms\RegistrationForm;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Persistence\FilePersistence;

class RegistrationController
{
    public function handle(Request $request)
    {
        $sessionId = $request->session()->getId();

        // Create form with persistence for multi-request handling
        $form = RegistrationForm::make()
            ->setChatHistory(new FileChatHistory("/tmp/chats/{$sessionId}"));

        // Process user message
        $handler = $form->process(
            new UserMessage($request->input('message'))
        );

        $state = $handler->run();

        return response()->json([
            'status' => $state->getStatus()->value,
            'message' => $handler->getLastResponse(),
            'completion' => $state->getCompletionPercentage(),
            'missing_fields' => $state->getMissingFields(),
            'is_complete' => $form->isComplete(),
        ]);
    }
}
```

### Handling Confirmation

When `requireConfirmation` is true, the form enters `WAIT_CONFIRM` status before submission:

```php
// Form collects all data and enters WAIT_CONFIRM status
$handler = $form->process(new UserMessage("That's all the info"));
$state = $handler->run();

if ($state->getStatus()->isWaitingConfirmation()) {
    // Show collected data to user for review
    $data = $state->getCollectedData();
    echo "Please confirm your details:";
    echo "Name: {$data->name}";
    echo "Email: {$data->email}";
    // ...
}

// User confirms - form submits and enters COMPLETE status
$handler = $form->process(new UserMessage("Yes, that's correct"));
$state = $handler->run();

echo $state->getStatus()->value;  // 'complete'
```

### Handling Cancellation

Users can cancel the form at any time using exit phrases:

```php
$handler = $form->process(new UserMessage("cancel"));
$state = $handler->run();

echo $state->getStatus()->value;  // 'closed'
```

### Accessing Form Data

```php
// Get collected data (available during collection)
$data = $form->getData();
// or
$data = $state->getCollectedData();

// Get submitted data (available after submission)
$submitted = $state->getSubmittedData();

// Get form state details
$missing = $state->getMissingFields();        // ['email', 'phone']
$errors = $state->getValidationErrors();      // ['name' => ['must not be blank']]
$completion = $state->getCompletionPercentage(); // 50
```

## Custom Exit Detection

Override `detectExit()` for custom cancellation logic:

```php
class SmartForm extends AIForm
{
    protected function detectExit(string $content): bool
    {
        // Add custom exit detection
        if (preg_match('/no\s+thanks|not\s+interested/i', $content)) {
            return true;
        }

        return parent::detectExit($content);
    }
}
```

## FormState Methods

The `FormState` class provides these methods:

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getStatus()` | `FormStatus` | Current form status |
| `getCollectedData()` | `?object` | Collected data object |
| `getSubmittedData()` | `?object` | Data after submission |
| `getMissingFields()` | `string[]` | List of missing field names |
| `getValidationErrors()` | `array<string, string[]>` | Errors by field |
| `getCompletionPercentage()` | `int` | 0-100 completion percentage |
| `isComplete()` | `bool` | All required fields collected |
| `getChatHistory()` | `ChatHistoryInterface` | Conversation history |

## FormStatus Enum

```php
enum FormStatus: string
{
    case INCOMPLETE = 'incomplete';      // Still collecting
    case WAIT_CONFIRM = 'wait_confirm';  // Awaiting confirmation
    case COMPLETE = 'complete';          // Submitted successfully
    case CLOSED = 'closed';              // Cancelled by user
}
```

## Validation

Use PHP attributes from `NeuronAI\StructuredOutput\Validation\Rules`:

- `#[NotBlank]` - Field must not be empty
- `#[Email]` - Must be valid email
- `#[Url]` - Must be valid URL
- `#[IPAddress]` - Must be valid IP
- `#[Length(min: 5, max: 100)]` - String length constraints
- `#[Count(min: 1, max: 10)]` - Array count constraints
- `#[GreaterThan(value: 0)]` - Numeric comparison
- `#[Enum]` - Must be valid enum value

```php
class ContactData
{
    #[SchemaProperty(description: 'Full name', required: true)]
    #[NotBlank]
    #[Length(min: 2, max: 100)]
    public string $name;

    #[SchemaProperty(description: 'Email', required: true)]
    #[Email]
    public string $email;

    #[SchemaProperty(description: 'Age')]
    #[GreaterThan(value: 0)]
    public ?int $age = null;
}
```
