/** @type {import('@commitlint/types').UserConfig} */
export default {
    defaultIgnores: true,
    // Registered exception: allow Dependabot bump / lockfile maintenance commits (workspace dependabot skill, Option B).
    ignores: [(message) => /^chore\((?:deps|deps-dev)\): (?:bump\s+|lockfile maintenance)/u.test(message)],
    rules: {
        'header-max-length': [2, 'always', 120],
        'header-min-length': [2, 'always', 10],
        'header-full-stop': [2, 'never', '.'],
        'type-enum': [
            2,
            'always',
            [
                'feat',
                'fix',
                'docs',
                'style',
                'refactor',
                'perf',
                'test',
                'build',
                'ci',
                'chore',
                'revert',
            ],
        ],
        'type-empty': [2, 'never'],
        'subject-empty': [2, 'never'],
        'type-case': [2, 'always', 'lower-case'],
        'subject-case': [2, 'always', ['sentence-case']],
    },
};
