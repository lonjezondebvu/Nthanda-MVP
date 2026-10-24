<?php

namespace App\Services;

use App\Models\Lesson;

class AssessmentMarker
{
    public function __construct(private LessonAssembler $assembler) {}

    private function normalized(mixed $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : '')));
    }

    public function mark(Lesson $lesson, array $responses): array
    {
        $data = $this->assembler->marking($lesson);
        $questions = array_column($data['questions'], null, 'id');
        $results = [];
        foreach ($data['rules'] as $rule) {
            $marks = 0;
            $answered = 0;
            $domains = [];
            foreach ($rule['required_question_ids'] as $id) {
                $q = $questions[$id];
                $response = $responses[$id] ?? null;
                $present = is_array($response) ? count($response) > 0 && count(array_filter($response, fn ($v) => $this->normalized($v) !== '')) === count($response) : $this->normalized($response) !== '';
                if ($q['kind'] === 'match') {
                    $present = $present && is_array($response) && count($response) === count($q['answer']);
                }
                if ($q['kind'] === 'order') {
                    $present = $present && is_array($response) && count($response) === count($q['answer']);
                }
                if ($present) {
                    $answered++;
                }
                $correct = false;
                switch ($q['kind']) {
                    case 'mc': $correct = $this->normalized($response) === $this->normalized($q['answer']);
                        break;
                    case 'short': $correct = in_array($this->normalized($response), array_map($this->normalized(...), (array) $q['answer']), true);
                        break;
                    case 'multi':
                        if (is_array($response)) {
                            $actual = array_map($this->normalized(...), $response);
                            $expected = array_map($this->normalized(...), $q['answer']);
                            sort($actual);
                            sort($expected);
                            $correct = $actual === $expected;
                        } break;
                    case 'order':
                        $correct = is_array($response) && array_map($this->normalized(...), array_values($response)) === array_map($this->normalized(...), $q['answer']);
                        break;
                    case 'match':
                        if (is_array($response)) {
                            $expected = array_map($this->normalized(...), $q['answer']);
                            $actual = array_map($this->normalized(...), $response);
                            ksort($expected);
                            ksort($actual);
                            $correct = $actual === $expected;
                        } break;
                }
                $score = $correct ? $q['maxMarks'] : 0;
                $marks += $score;
                $domain = $q['domain'];
                $domains[$domain] ??= ['score' => 0, 'max' => 0];
                $domains[$domain]['score'] += $score;
                $domains[$domain]['max'] += $q['maxMarks'];
            }
            $domainPass = true;
            if ($rule['domain_minimum_enabled'] ?? false) {
                foreach ($domains as $domain) {
                    if ($rule['minimum_each_domain_ratio'] > $domain['score'] / $domain['max']) {
                        $domainPass = false;
                    }
                }
            }
            $results[] = ['criterion_id' => $rule['criterion_id'], 'rule_version' => $rule['rule_version'], 'score' => $marks, 'max_marks' => $rule['max_marks'],
                'answered' => $answered, 'required' => count($rule['required_question_ids']), 'minimum_ratio' => $rule['minimum_overall_ratio'],
                'passed' => ($rule['enabled'] ?? true) && $answered === count($rule['required_question_ids']) && $marks / $rule['max_marks'] >= $rule['minimum_overall_ratio'] && $domainPass];
        }

        return $results;
    }
}
