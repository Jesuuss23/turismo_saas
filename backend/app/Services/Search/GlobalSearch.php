<?php

namespace App\Services\Search;

use App\Models\Iam\User;
use App\Security\Access;

class GlobalSearch
{
    public const MAX_RESULTS = 12;

    /** @var list<class-string<SearchProvider>> */
    private array $providers = [];

    public function register(string $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return list<array> */
    public function search(User $user, string $term): array
    {
        $subject = $user->accessSubject();
        $results = [];

        foreach ($this->providers as $provider) {
            foreach (app($provider)->search($user, $term, self::MAX_RESULTS) as $hit) {
                if (Access::check($subject, $hit['route'])->allowed) {
                    $results[] = $hit;
                }
                if (count($results) >= self::MAX_RESULTS) {
                    return $results;
                }
            }
        }

        return $results;
    }
}
