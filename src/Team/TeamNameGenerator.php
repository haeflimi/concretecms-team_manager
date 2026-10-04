<?php
namespace TeamManager\Team;

/**
 * Combines a random adjective and noun from the package config (team_name_adjectives, team_name_nouns)
 * to a team name, e.g. "Furious Llamas". Names that are already taken are skipped.
 */
class TeamNameGenerator
{
    /** @var TeamConfig */
    protected $config;
    /** @var TeamRepository */
    protected $teams;

    public function __construct(TeamConfig $config, TeamRepository $teams)
    {
        $this->config = $config;
        $this->teams = $teams;
    }

    /**
     * @return string empty if a list is empty or no free name was found
     */
    public function generate(int $attempts = 25): string
    {
        $adjectives = $this->getWords('team_name_adjectives');
        $nouns = $this->getWords('team_name_nouns');
        if (!$adjectives || !$nouns) {
            return '';
        }
        for ($i = 0; $i < $attempts; $i++) {
            $name = mb_substr($adjectives[array_rand($adjectives)] . ' ' . $nouns[array_rand($nouns)], 0, 64);
            if (!$this->teams->nameExists($name)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * The lists are comma separated strings, so an override replaces them as a whole
     * (arrays would be merged with the defaults by index). Arrays are accepted too.
     *
     * @return string[]
     */
    protected function getWords(string $key): array
    {
        $value = $this->config->get($key, '');
        $words = is_array($value) ? $value : explode(',', (string) $value);
        $words = array_map(function ($word) {
            return trim((string) $word);
        }, $words);

        return array_values(array_unique(array_filter($words, 'strlen')));
    }
}
