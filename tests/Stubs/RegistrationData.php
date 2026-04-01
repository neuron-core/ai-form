<?php

declare(strict_types=1);

namespace NeuronAI\Form\Tests\Stubs;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\Email;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

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
