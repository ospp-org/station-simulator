<?php

declare(strict_types=1);

namespace App\Scenarios\Results;

final class JunitXmlExporter
{
    public function export(ScenarioResult $result): string
    {
        $xml = new \SimpleXMLElement('<testsuites/>');
        $suite = $xml->addChild('testsuite');
        $suite->addAttribute('name', $result->name);
        $suite->addAttribute('tests', (string) $result->getStepCount());
        $suite->addAttribute('failures', (string) $result->getFailedCount());
        $suite->addAttribute('time', (string) round($result->getDurationMs() / 1000, 3));
        $suite->addAttribute('timestamp', $result->startedAt?->format('Y-m-d\TH:i:s') ?? '');

        foreach ($result->steps as $step) {
            $testCase = $suite->addChild('testcase');
            $testCase->addAttribute('name', $step->name);
            $testCase->addAttribute('time', (string) round($step->durationMs / 1000, 3));

            if ($step->failed()) {
                $failure = $testCase->addChild('failure', htmlspecialchars($step->details));
                $failure->addAttribute('type', 'AssertionError');
            } elseif ($step->status === 'skip') {
                $testCase->addChild('skipped');
            }
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        return $dom->saveXML();
    }

    public function exportToFile(ScenarioResult $result, string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->export($result));
    }
}
