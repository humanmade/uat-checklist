---
paths:
    - '**/*.{js,mjs,scss,css,json,md}'
    - '.prettierignore'
    - 'prettier.config.js'
    - 'eslint.config.js'
    - '.stylelintrc.json'
---

# Formatting ownership

Loads when a JS, stylesheet, JSON or Markdown file, or one of the formatter configs, is read.

- **ESLint owns JS**, with Prettier run inside it as `prettier/prettier`. `npm run lint:js:fix` applies both.
- **Stylelint owns SCSS.** `@wordpress/stylelint-config/scss` wants blank lines Prettier would strip, so
  Prettier never formats SCSS.
- **PHPCS owns PHP.** The `php-standards` rule covers it.

`prettier` here is the wp-prettier fork, which `@wordpress/scripts` resolves it to, and `prettier.config.js`
turns `parenSpacing` off. JS therefore formats as `foo(bar)`, not WordPress core's `foo( bar )`. Removing that
line reformats every call in `src/` and `blocks/`.

ESLint's flat config reads no `.eslintignore`; its ignores live in the first object of `eslint.config.js`. A new
`@wordpress/*` import needs a line in that file's `import/core-modules`, or `import/no-unresolved` fails it. The
skill's scripts under `.claude/skills/*/scripts/` may use `console`, since printing is how they report.

Markdown lint runs over the paths in the `lint:md` script only: `README.md`, `AGENTS.md` and `.claude/`. A new
Markdown location needs adding there.

Do not open a JSDoc block (`/**`) whose body has a line starting with an `@`-prefixed package name.
`jsdoc/check-tag-names` reads `@wordpress/scripts` as an unknown tag. Use a plain `/*` banner instead.
