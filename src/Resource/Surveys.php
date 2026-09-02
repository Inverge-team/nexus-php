<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * In-product surveys — fetch the surveys a user is eligible to see and submit
 * their answers. Useful server-side for headless/API surveys and for rendering
 * surveys in server-driven UIs.
 */
final class Surveys extends AbstractResource
{
    /**
     * The surveys a user is currently eligible to see (targeting + sampling +
     * frequency capping applied server-side).
     *
     * @param array{distinctId?:string,deviceKey?:string,properties?:array<string,mixed>,deviceType?:string,osType?:string} $options
     *
     * @return array<mixed> list of render-ready surveys
     */
    public function active(array $options = []): array
    {
        $result = $this->client->request('POST', '/partner/surveys/active', $this->compact([
            'distinctId' => $options['distinctId'] ?? null,
            'deviceKey' => $options['deviceKey'] ?? null,
            'properties' => ($options['properties'] ?? null) ?: null,
            'deviceType' => $options['deviceType'] ?? null,
            'osType' => $options['osType'] ?? null,
        ]));

        return $result['surveys'] ?? [];
    }

    /**
     * Submit a survey response (partial or complete). Answers are keyed by
     * question id.
     *
     * @param array<string, mixed> $answers
     * @param array{responseId?:string,completed?:bool,dismissed?:bool,iterationKey?:string,distinctId?:string,sessionKey?:string,deviceKey?:string,osType?:string,osVersion?:string,browser?:string,appVersion?:string,country?:string,url?:string} $options
     *
     * @return array<mixed> { id, completed }
     */
    public function respond(string $surveyId, array $answers, array $options = []): array
    {
        return $this->client->request('POST', '/partner/surveys/responses', $this->compact([
            'surveyId' => $surveyId,
            // Must serialise as a JSON object, never [] (the API validates it).
            'answers' => $answers === [] ? new \stdClass() : $answers,
            'responseId' => $options['responseId'] ?? null,
            'completed' => $options['completed'] ?? null,
            'dismissed' => $options['dismissed'] ?? null,
            'iterationKey' => $options['iterationKey'] ?? null,
            'distinctId' => $options['distinctId'] ?? null,
            'sessionKey' => $options['sessionKey'] ?? null,
            'deviceKey' => $options['deviceKey'] ?? null,
            'osType' => $options['osType'] ?? null,
            'osVersion' => $options['osVersion'] ?? null,
            'browser' => $options['browser'] ?? null,
            'appVersion' => $options['appVersion'] ?? null,
            'country' => $options['country'] ?? null,
            'url' => $options['url'] ?? null,
        ])) ?? [];
    }

    /**
     * Submit a completed response in one call.
     *
     * @param array<string, mixed> $answers
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    public function complete(string $surveyId, array $answers, array $options = []): array
    {
        return $this->respond($surveyId, $answers, ['completed' => true] + $options);
    }

    /**
     * Record that a user dismissed a survey without completing it.
     *
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    public function dismiss(string $surveyId, array $options = []): array
    {
        return $this->respond($surveyId, [], ['dismissed' => true] + $options);
    }
}
