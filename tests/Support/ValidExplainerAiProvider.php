<?php

namespace Tests\Support;

use App\Services\AI\MockAiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\SkillAgent\SkillAssetLoader;

/** A valid renderer example for orchestration tests, independent of the demo text mock. */
class ValidExplainerAiProvider extends MockAiProvider
{
    public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
    {
        if (($options['feature_id'] ?? null) !== 'yao-geo-explainer-builder') {
            return parent::generate($prompt, $system, $options);
        }

        // The upstream skill source copy is not versioned here; fall back to the
        // synthetic example kept with the tests when it is not installed.
        $example = (new SkillAssetLoader)->loadExampleJson('yao-geo-explainer-builder')
            ?? json_decode((string) file_get_contents(
                dirname(__DIR__).'/fixtures/skills/yao-geo-explainer-builder/report_input.json'
            ), true);
        if (! is_array($example)) {
            throw new \RuntimeException('The explainer renderer example is unavailable.');
        }
        $content = json_encode($example, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new AiProviderCallResult(
            success: true,
            provider: 'fixture',
            model: 'fixture-explainer',
            content: $content,
            promptTokens: 400,
            completionTokens: 1000,
            totalTokens: 1400,
            finishReason: 'stop',
        );
    }
}
