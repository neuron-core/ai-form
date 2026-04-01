<?php

declare(strict_types=1);

namespace NeuronAI\Form;

use NeuronAI\Providers\AIProviderInterface;

trait HandleProvider
{
    public function setAiProvider(AIProviderInterface $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        return $this->provider;
    }

    /**
     * Get the current provider instance.
     */
    protected function resolveProvider(): AIProviderInterface
    {
        return $this->provider ??= $this->provider();
    }
}
