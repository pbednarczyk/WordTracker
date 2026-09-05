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
- Study scheduling and card generation are separate responsibilities.
- Study queue ordering must avoid adjacent sibling cards for the same
  `VocabularyItem` where feasible.
- Ordinary vocabulary must not automatically generate open `CLOZE` cards solely
  by replacing the target token.
- Study UI must not leak the answer before Reveal.
- `LearningReview` is an immutable review event for one shown `LearningCard`;
  it is not attached directly to `VocabularyItem`.
- Review ratings are self-assessment labels only and must not mutate
  `VocabularyItem.status`, card activation, or publication coverage.
- Review persistence must be idempotent per study session presentation
  (`studySessionId + studyPosition`) so duplicate POSTs do not create duplicate
  review rows.
- Smart study queue ordering and future due/scheduling logic are separate
  concerns. Do not implement FSRS scheduling inside `SmartStudyQueueBuilder`.
