## API changes

Every time you add, remove, rename, or modify a public HTTP endpoint:

1. Update the Bruno collection in `bruno/`.
2. Add or update example requests.
3. Update environment variables if required.
4. Update request examples and assertions if the response contract changed.
5. Update README/API documentation if the contract changed.
6. Keep Bruno requests synchronized with actual endpoint paths,
   request bodies and response contracts.

Do not treat Bruno as optional documentation. The Bruno collection is part of
the API contract and must stay in sync with the code.

## Database safety

- Never run destructive test setup against the development database.
- PHPUnit must use `wordtracker_test`.
- Any destructive reset helper must verify the active database name before
  `TRUNCATE`, `DROP`, or reset-style `DELETE FROM`.
- Never change test `DATABASE_URL` to the development database for convenience.

## Learning cards

- `VocabularyItem` is not a `LearningCard`.
- One `VocabularyItem` may have multiple contextual `LearningCard` records,
  especially when the same lemma appears with different meanings in different
  publications.
- Learning card generation must be idempotent. Creating cards for the same
  `PublicationVocabulary` and card type must not create duplicates.
