# Projekt: Personal Vocabulary Intelligence System

## 1. Cel projektu

Celem projektu jest stworzenie osobistego systemu do nauki i śledzenia znajomości słownictwa języka angielskiego na podstawie **realnych treści konsumowanych przez użytkownika**, takich jak:

- książki,
- komiksy,
- artykuły,
- dokumenty,
- publikacje PDF,
- wklejony tekst,
- zdjęcia stron,
- inne materiały tekstowe.

System nie ma być wyłącznie aplikacją do fiszek. Jego główną rolą jest zbudowanie **długoterminowej, rozwijanej przez lata bazy poznanego słownictwa użytkownika**, a następnie wykorzystywanie jej do analizy kolejnych publikacji.

Najważniejsza idea:

> Użytkownik dostarcza treść publikacji, system wykrywa słownictwo, którego użytkownik jeszcze nie zna, pomaga się go nauczyć, a następnie aktualizuje globalną bazę znajomości języka.

W efekcie użytkownik może określić:

- ile słownictwa z konkretnej publikacji już zna,
- ile nowych słów zawiera dana publikacja,
- których słów powinien nauczyć się przed czytaniem,
- jaki procent tekstu jest dla niego zrozumiały,
- które wcześniej poznane słowa zaczynają być zapominane,
- jak jego zasób słownictwa rozwija się w czasie.

---

# 2. Główne założenia produktu

## 2.1. Własna baza słownictwa jako Source of Truth

Centralnym elementem systemu jest własna baza słownictwa użytkownika.

Nie powinna być ona zależna od zewnętrznej aplikacji typu Anki, Memrise, Knowt czy Brainscape.

System powinien umożliwiać eksport danych do innych narzędzi, ale to **lokalna / własna baza projektu pozostaje głównym źródłem prawdy**.

Dzięki temu użytkownik nie jest zależny od:

- abonamentu zewnętrznej usługi,
- zamknięcia serwisu,
- zmian polityki cenowej,
- ograniczeń importu lub eksportu,
- zmian formatu danych.

---

## 2.2. Nauka na podstawie realnych treści

System nie powinien narzucać użytkownikowi z góry przygotowanych list typu:

- 1000 najczęstszych angielskich słów,
- poziom B1,
- poziom C1,
- słownictwo biznesowe.

Zamiast tego materiałem wejściowym są **rzeczywiste publikacje, które użytkownik chce przeczytać**.

Przykład:

1. użytkownik kupuje angielski komiks,
2. skanuje lub fotografuje strony,
3. system analizuje tekst,
4. porównuje słownictwo z globalną bazą użytkownika,
5. wykrywa 127 nowych słów i 14 nowych wyrażeń,
6. użytkownik uczy się ich,
7. system oznacza publikację jako 100% pokrytą znanym słownictwem.

---

# 3. Podstawowy workflow

## Krok 1. Dodanie publikacji

Użytkownik tworzy nowy materiał, np.:

- książkę,
- komiks,
- artykuł,
- dokument,
- wpis blogowy,
- dowolny tekst.

Przykładowe dane:

```text
Title: The Hobbit
Type: Book
Language: English
Author: J.R.R. Tolkien
```

---

## Krok 2. Dostarczenie treści

System powinien obsługiwać kilka źródeł:

### MVP

- wklejenie tekstu,
- upload TXT,
- upload CSV.

### Kolejne wersje

- PDF,
- EPUB,
- zdjęcia,
- skany,
- zdjęcia pojedynczych stron,
- automatyczne OCR.

---

## Krok 3. Ekstrakcja i normalizacja tekstu

Tekst jest:

1. oczyszczany,
2. dzielony na zdania,
3. tokenizowany,
4. normalizowany,
5. poddawany lematyzacji.

Przykład:

```text
run
runs
running
ran
```

powinny zostać powiązane z jednym podstawowym hasłem:

```text
run
```

---

# 4. Lematyzacja

System nie powinien traktować każdej odmiany słowa jako osobnego słowa.

Przykłady:

```text
cars      -> car
children  -> child
went      -> go
running   -> run
```

Globalna baza powinna operować przede wszystkim na **lematach**, czyli podstawowych formach słów.

Jednocześnie system powinien zapisywać zaobserwowane formy.

Przykład:

```yaml
lemma: run
forms:
  - run
  - runs
  - running
  - ran
```

---

# 5. Frazy, idiomy i phrasal verbs

Samo analizowanie pojedynczych słów jest niewystarczające.

Znajomość:

```text
give
up
```

nie oznacza automatycznie znajomości:

```text
give up
```

System powinien docelowo wykrywać również:

- phrasal verbs,
- idiomy,
- kolokacje,
- popularne wyrażenia wielowyrazowe,
- stałe konstrukcje językowe.

Przykłady:

```text
give up
look after
figure out
get away with
by the way
as long as
```

Powinny one istnieć w systemie jako osobne jednostki wiedzy.

---

# 6. Globalna baza słownictwa

System powinien posiadać globalny słownik użytkownika.

Przykładowy rekord:

```yaml
lemma: corridor
language: en
part_of_speech: noun
status: MATURE
first_seen_at: 2026-08-27
first_source: The Hobbit
times_seen: 42
times_reviewed: 8
last_reviewed_at: 2026-08-23
```

Możliwe dodatkowe dane:

- tłumaczenie,
- definicja,
- przykłady zdań,
- wymowa,
- IPA,
- synonimy,
- antonimy,
- poziom CEFR,
- tagi,
- lista publikacji, w których słowo wystąpiło.

---

# 7. Status znajomości słowa

Status nie powinien być prostym:

```text
known = true / false
```

Proponowany model:

```text
NEW
LEARNING
KNOWN
MATURE
LAPSED
```

## NEW

Słowo wykryte, ale użytkownik jeszcze się go nie uczył.

## LEARNING

Słowo znajduje się aktualnie w procesie nauki.

## KNOWN

Słowo zostało poprawnie zapamiętane, ale nie posiada jeszcze wysokiej stabilności pamięciowej.

## MATURE

Słowo jest dobrze utrwalone.

## LAPSED

Słowo było wcześniej znane, ale użytkownik zaczął je zapominać.

---

# 8. Integracja z systemem powtórek

Docelowo system powinien posiadać własny mechanizm powtórek albo integrować gotowy algorytm SRS.

Preferowany algorytm:

**FSRS**.

Przykład danych:

```yaml
word: corridor
state: MATURE
stability_days: 93
retrievability: 0.96
last_review: 2026-08-23
```

Dzięki temu system może oszacować, czy dane słowo nadal jest prawdopodobnie pamiętane.

---

# 9. Pokrycie słownictwa publikacji

System powinien liczyć co najmniej dwa rodzaje pokrycia.

## 9.1. Unique Vocabulary Coverage

Procent unikalnych lematów znanych przez użytkownika.

Przykład:

```text
Unique lemmas: 6500
Known lemmas: 5200

Unique vocabulary coverage:
80%
```

---

## 9.2. Text Coverage

Znacznie ważniejsza metryka.

Uwzględnia liczbę wystąpień każdego słowa w tekście.

Przykład:

Publikacja ma:

```text
100 000 wszystkich słów
6500 unikalnych lematów
```

Użytkownik zna tylko 80% unikalnych lematów, ale znane słowa odpowiadają za:

```text
98.3% wszystkich wystąpień
```

Wtedy:

```text
Unique vocabulary coverage: 80%
Text coverage:              98.3%
```

To lepiej odzwierciedla realną trudność tekstu.

---

# 10. Priorytetyzacja nowych słów

Nie każde nowe słowo powinno być traktowane jednakowo.

Przykład:

```text
corridor        42 occurrences
wand            37 occurrences
cloak           28 occurrences
staircase       21 occurrences
cauldron         9 occurrences
haberdashery     1 occurrence
```

System powinien umieć sortować słowa według:

- liczby wystąpień,
- wpływu na text coverage,
- ogólnej częstotliwości w języku,
- poziomu CEFR,
- znaczenia w danej publikacji.

Przykładowa funkcja:

> Naucz się 63 najważniejszych nowych słów, aby zwiększyć pokrycie tekstu z 89% do 97%.

---

# 11. Analiza trudności publikacji

System powinien pozwolić przeanalizować tekst **przed jego przeczytaniem**.

Przykład:

```text
Project Hail Mary

Vocabulary coverage: 96.4%

Unknown unique words:
482

High-value unknown words:
76

Estimated difficulty:
MEDIUM

Estimated preparation:
3-4 study sessions
```

Kluczowa różnica:

System nie mówi:

> Ta książka jest na poziomie C1.

System mówi:

> Ta książka jest dla tego konkretnego użytkownika trudna w 3.6%.

---

# 12. Historia kontaktu ze słowem

Każde słowo powinno posiadać historię występowania.

Przykład:

```text
stagger

First encountered:
The Hobbit - chapter 6

Also encountered:
Project Hail Mary
The Guardian article #184
Watchmen #7

Seen: 17 times
Reviews: 8
Status: MATURE
```

Pozwala to użytkownikowi zobaczyć, jak dane słowo przewija się przez różne materiały.

---

# 13. Publikacja jako obiekt domenowy

Każda publikacja powinna przechowywać:

```yaml
id:
title:
author:
type:
language:
created_at:
word_count:
unique_lemma_count:
unknown_lemma_count:
known_lemma_count:
text_coverage:
unique_vocabulary_coverage:
```

Typy publikacji:

```text
BOOK
COMIC
ARTICLE
DOCUMENT
WEB
OTHER
```

---

# 14. Rozdziały / sekcje

Docelowo książka powinna posiadać podział:

```text
Publication
 ├── Chapter 1
 ├── Chapter 2
 ├── Chapter 3
 └── ...
```

Pozwala to uczyć się materiału stopniowo.

Przykład:

```text
The Hobbit

Chapter 1
92% coverage
37 new words

Chapter 2
96% coverage
18 new words
```

---

# 15. Generowanie materiałów do nauki

Nowe słowo nie powinno tworzyć jedynie fiszki:

```text
utter -> wypowiedzieć
```

System powinien korzystać z oryginalnego kontekstu.

Przykład źródłowy:

```text
He did not utter a word.
```

Automatycznie wygenerowana karta może zawierać:

```text
WORD
utter

MEANING
wypowiedzieć / wydać z siebie

CONTEXT
He did not utter a word.

CLOZE
He did not _____ a word.

RELATED
speak
say
```

---

# 16. AI enrichment

AI może wzbogacać znalezione słowa o:

- tłumaczenie,
- znaczenie w konkretnym kontekście,
- definicję angielską,
- część mowy,
- przykład,
- uproszczony przykład,
- cloze sentence,
- synonimy,
- antonimy,
- poziom CEFR,
- informację o formalności,
- informację o częstotliwości,
- wykrywanie idiomów,
- wykrywanie phrasal verbs.

Kluczowe założenie:

**AI otrzymuje całe zdanie lub fragment tekstu, a nie samo słowo.**

Pozwala to rozróżnić znaczenia.

Przykład:

```text
bat
```

może oznaczać:

```text
nietoperz
```

lub:

```text
kij
```

Kontekst rozwiązuje tę niejednoznaczność.

---

# 17. Nauka

Podstawowe typy ćwiczeń:

## Flashcard

```text
corridor
→ ?
```

## Reverse flashcard

```text
korytarz
→ ?
```

## Cloze

```text
He walked down the _____.
```

## Multiple choice

Wybór poprawnego znaczenia.

## Typing

Użytkownik wpisuje odpowiedź.

---

# 18. Dashboard użytkownika

Przykładowy dashboard:

```text
MY ENGLISH VOCABULARY

Known lemmas:       8 427
Learning:             314
Mature:             6 891

Books completed:       14
Articles scanned:      87
Comics:                21

Current streak:        47 days
```

---

# 19. Dashboard publikacji

Przykład:

```text
The Hobbit

Unique vocabulary
██████████████████░░ 91.2%

Text coverage
███████████████████▊ 98.7%

New lemmas: 413
Important new lemmas: 87

Estimated study:
~320 reviews
```

---

# 20. Aktualna znajomość publikacji

Pokrycie publikacji nie powinno być wieczne.

Jeżeli użytkownik zacznie zapominać słowa, system może pokazać:

```text
The Hobbit

Coverage at completion:
100%

Current estimated coverage:
96.8%

23 words need review
```

Możliwa akcja:

```text
Restore to 100%
```

Powoduje utworzenie krótkiej sesji powtórkowej.

---

# 21. Grywalizacja

System może posiadać:

- XP,
- levele,
- streak,
- achievementy,
- cele dzienne,
- heatmapę aktywności,
- statystyki serii,
- rekord najdłuższego streaka.

Przykład:

```text
Level 17

██████████████░░ 73%

Current streak:
47 days

Today:
32 / 40 reviews
12 new words
91.7% retention
```

Przykładowe achievementy:

```text
100 words learned
1000 reviews
30 day streak
First book completed
10 books completed
5000 known lemmas
95% retention
100% publication coverage
```

---

# 22. Statystyki

System powinien śledzić:

- liczbę znanych słów,
- liczbę nowych słów,
- liczbę powtórek,
- retention,
- średni response time,
- reviews/day,
- new words/day,
- vocabulary growth,
- words forgotten,
- words regained,
- predicted review workload,
- difficulty distribution,
- streak,
- liczba przeanalizowanych publikacji.

---

# 23. Szczególnie ciekawa metryka

Wykres:

```text
new vocabulary / publication
```

Przykład:

```text
The Hobbit                  413
Fellowship of the Ring      287
The Two Towers              119
Return of the King           73
```

Malejąca liczba nowych słów w kolejnych publikacjach może wizualizować realny rozwój znajomości języka.

---

# 24. Import i eksport

System powinien traktować import / eksport jako funkcję pierwszej klasy.

## Import

Docelowo:

- CSV,
- XLSX,
- JSON,
- Anki APKG,
- TXT.

Przykładowy CSV:

```csv
english,polish,example,tags,level
deploy,wdrażać,"We deployed the application","IT;work",B2
scarce,rzadki,"Resources are scarce",general,C1
```

---

## Eksport

Minimum:

- CSV,
- XLSX,
- JSON.

Docelowo:

- Anki,
- inne aplikacje SRS.

Eksport powinien umożliwiać wybór:

```text
ALL WORDS
NEW WORDS
LEARNING
KNOWN
MATURE
WORDS FROM PUBLICATION
WORDS FROM CHAPTER
```

---

# 25. Architektura logiczna

Przykładowy pipeline:

```text
Book / Comic / Article
        ↓
Text / PDF / EPUB / Images
        ↓
OCR / Text Extraction
        ↓
Text Normalization
        ↓
Sentence Segmentation
        ↓
Tokenization
        ↓
Lemmatization
        ↓
Phrase Detection
        ↓
Vocabulary DB comparison
        ↓
Unknown vocabulary
        ↓
AI enrichment
        ↓
Learning Queue
        ↓
SRS
        ↓
Known Vocabulary DB
```

---

# 26. Proponowany stack

Projekt nie powinien być silnie uzależniony od konkretnego stacku, ale przykładowa implementacja może wyglądać następująco:

## Backend

```text
PHP
Symfony
```

## Database

```text
PostgreSQL
```

## Frontend

Do wyboru:

```text
React
Vue
Svelte
```

## Mobile

Na początek:

```text
PWA
```

Pozwala to uniknąć tworzenia osobnej aplikacji iOS.

Docelowo można rozważyć:

```text
React Native
Flutter
native iOS / Android
```

---

# 27. Proponowany model domenowy

Najważniejsze encje:

```text
User
Publication
PublicationSection
VocabularyItem
VocabularyForm
VocabularyOccurrence
VocabularyMeaning
Review
LearningCard
Achievement
Import
```

---

# 28. VocabularyItem

Przykładowe pola:

```yaml
id:
language:
lemma:
part_of_speech:
status:
translation:
definition:
cefr_level:
first_seen_at:
last_seen_at:
times_seen:
created_at:
updated_at:
```

---

# 29. VocabularyOccurrence

Reprezentuje konkretne wystąpienie słowa.

```yaml
id:
vocabulary_item_id:
publication_id:
section_id:
sentence:
original_form:
position:
created_at:
```

Dzięki temu można odpowiedzieć:

> Gdzie wcześniej widziałem to słowo?

---

# 30. Review

Przykładowy rekord:

```yaml
id:
vocabulary_item_id:
reviewed_at:
rating:
response_time_ms:
previous_state:
new_state:
stability:
difficulty:
retrievability:
next_review_at:
```

---

# 31. LearningCard

Słowo i karta nie powinny być tym samym obiektem.

Jedno VocabularyItem może generować wiele kart.

Przykład:

```text
VocabularyItem:
utter
```

Karty:

```text
utter -> Polish
Polish -> utter
cloze
meaning recognition
```

---

# 32. MVP

Celem MVP jest potwierdzenie, że główny workflow jest wartościowy.

## MVP 1 - Vocabulary Analyzer

### Funkcje

- dodanie publikacji,
- wklejenie tekstu,
- tokenizacja,
- lematyzacja,
- wykrycie unikalnych słów,
- liczba wystąpień,
- porównanie z bazą słownictwa,
- lista nowych słów,
- ręczne oznaczanie słów:
  - known,
  - unknown,
- eksport nowych słów do CSV/XLSX,
- podstawowy dashboard publikacji.

### Bez

- OCR,
- AI,
- SRS,
- aplikacji mobilnej,
- phrasal verbs,
- rozbudowanej grywalizacji.

### Cel MVP

Odpowiedzieć na pytanie:

> Czy analiza realnej publikacji i automatyczne wykrywanie nieznanego słownictwa faktycznie daje użytkownikowi wartość?

---

# 33. MVP 2 - Learning Loop

Po udanym MVP 1.

### Funkcje

- własne fiszki,
- statusy:
  - NEW,
  - LEARNING,
  - KNOWN,
  - MATURE,
- historia powtórek,
- integracja FSRS,
- kolejka powtórek,
- automatyczna aktualizacja globalnej bazy słownictwa,
- aktualizacja coverage publikacji.

### Cel

Zamknięcie pełnej pętli:

```text
publication
→ unknown vocabulary
→ learn
→ known vocabulary
→ next publication
```

---

# 34. MVP 3 - Intelligent Content Scanner

### Funkcje

- PDF,
- EPUB,
- OCR zdjęć,
- wykrywanie rozdziałów,
- automatyczna ekstrakcja tekstu,
- analiza wielu stron,
- historia importów.

### Cel

Użytkownik powinien móc:

1. zrobić zdjęcia książki,
2. wrzucić materiał,
3. otrzymać listę nowych słów.

---

# 35. MVP 4 - AI Enrichment

### Funkcje

- tłumaczenie w kontekście,
- definicje,
- automatyczny cloze,
- część mowy,
- CEFR,
- synonimy,
- wykrywanie znaczenia,
- inteligentne generowanie kart.

### Cel

Minimalizacja ręcznej pracy przy przygotowaniu materiału.

---

# 36. MVP 5 - Multiword Expressions

### Funkcje

- phrasal verbs,
- idiomy,
- kolokacje,
- multi-word expressions.

Przykład:

```text
figure out
give up
look after
```

traktowane jako osobne elementy słownictwa.

---

# 37. MVP 6 - Gamification & Analytics

### Funkcje

- XP,
- level,
- streak,
- achievements,
- heatmapa,
- daily goals,
- retention dashboard,
- growth dashboard,
- vocabulary per publication,
- predicted workload.

---

# 38. MVP 7 - Personal Difficulty Engine

System analizuje publikację względem aktualnej wiedzy użytkownika.

### Wynik

```text
Vocabulary coverage
Unknown vocabulary
Important unknown vocabulary
Estimated difficulty
Estimated study effort
```

Możliwa funkcja:

```text
Prepare me for this book
```

System tworzy minimalny zestaw słów pozwalający osiągnąć np.:

```text
95%
97%
98%
99%
```

text coverage.

---

# 39. MVP 8 - Library Intelligence

Biblioteka użytkownika staje się mapą jego znajomości języka.

Przykład:

```text
Harry Potter
100%

The Hobbit
100%

Project Hail Mary
97.2%

Watchmen
94.8%
```

Możliwe filtry:

```text
READY TO READ
NEEDS PREPARATION
COMPLETED
NEEDS REVIEW
```

---

# 40. Potencjalne rozszerzenia

## Browser extension

Przycisk:

```text
Analyze this page
```

dla artykułów internetowych.

---

## Kindle / ebook workflow

Import zaznaczonych słów i fragmentów.

---

## OCR live camera

Telefon skierowany na stronę i podświetlanie nieznanych słów.

---

## Reading mode

Tekst wyświetlany w aplikacji.

Nieznane słowa:

- podkreślone,
- oznaczone kolorem,
- tłumaczenie po kliknięciu.

---

## Smart highlights

System może automatycznie zaznaczać słowa:

```text
KNOWN
LEARNING
UNKNOWN
```

---

## Vocabulary recommendation

System może sugerować:

> Naucz się tych 40 słów przed rozpoczęciem następnego rozdziału.

---

# 41. Własność danych

Projekt powinien być projektowany zgodnie z zasadą:

> User owns the vocabulary.

Dane powinny być możliwe do pełnego eksportu.

Minimum:

```text
CSV
JSON
XLSX
```

System nie powinien utrudniać migracji.

---

# 42. Możliwość pracy lokalnej

Docelowo warto umożliwić tryb:

```text
self-hosted
```

lub przynajmniej:

```text
local-first
```

Szczególnie globalny słownik i historia nauki powinny być łatwe do backupowania.

---

# 43. Backup

Minimum:

- automatyczny backup bazy,
- eksport JSON,
- eksport CSV/XLSX.

Opcjonalnie:

- backup do Google Drive,
- backup do iCloud,
- backup do własnego S3.

---

# 44. Ważne problemy techniczne

## Lematyzacja

Nie zawsze jest jednoznaczna.

Przykład:

```text
saw
```

może być:

```text
see -> past tense
```

albo:

```text
saw -> noun
```

Dlatego lematyzacja powinna brać pod uwagę kontekst i część mowy.

---

## Proper nouns

System powinien rozpoznawać:

- imiona,
- nazwiska,
- nazwy miejsc,
- fikcyjne nazwy,
- nazwy organizacji.

Nie powinny automatycznie trafiać do kolejki nauki.

---

## OCR errors

OCR może generować fałszywe słowa.

Należy umożliwić:

- confidence score,
- podgląd oryginalnego fragmentu,
- ręczne odrzucenie.

---

## Homographs

Jedno słowo może mieć kilka znaczeń.

Przykład:

```text
bank
```

może oznaczać:

- bank finansowy,
- brzeg rzeki.

Znaczenie powinno być przypisane do kontekstu.

---

# 45. Słownik pojęć

## OCR - Optical Character Recognition

Technologia rozpoznawania tekstu na zdjęciach i skanach.

Przykład:

```text
zdjęcie strony książki
→ OCR
→ edytowalny tekst
```

---

## SRS - Spaced Repetition System

System powtórek rozłożonych w czasie.

Słowa dobrze zapamiętane są powtarzane rzadziej, a trudniejsze częściej.

---

## FSRS - Free Spaced Repetition Scheduler

Nowoczesny algorytm planowania powtórek wykorzystywany m.in. przez Anki.

Modeluje między innymi:

- trudność materiału,
- stabilność pamięci,
- prawdopodobieństwo przypomnienia.

---

## Lemma / lemat

Podstawowa forma słowa.

Przykład:

```text
running
ran
runs
```

należą do:

```text
run
```

---

## Lemmatization / lematyzacja

Proces sprowadzania różnych form fleksyjnych słowa do lematu.

---

## Token

Pojedyncza jednostka tekstu wykryta przez analizator.

Najczęściej jest to słowo, liczba lub znak.

---

## Tokenization / tokenizacja

Proces dzielenia tekstu na tokeny.

---

## NLP - Natural Language Processing

Przetwarzanie języka naturalnego.

Zbiór technik służących komputerowej analizie tekstu.

W projekcie NLP może odpowiadać za:

- tokenizację,
- lematyzację,
- rozpoznawanie części mowy,
- wykrywanie nazw własnych,
- wykrywanie fraz.

---

## POS - Part of Speech

Część mowy.

Przykłady:

```text
noun
verb
adjective
adverb
```

---

## CEFR

Common European Framework of Reference for Languages.

Popularna skala poziomu językowego:

```text
A1
A2
B1
B2
C1
C2
```

---

## Cloze

Typ ćwiczenia polegający na uzupełnianiu brakującego fragmentu zdania.

Przykład:

```text
He did not _____ a word.
```

---

## Phrasal verb

Czasownik połączony z przyimkiem lub przysłówkiem, którego znaczenie może różnić się od znaczenia pojedynczych słów.

Przykład:

```text
give up
figure out
look after
```

---

## Collocation / kolokacja

Naturalne połączenie słów często używane razem.

Przykład:

```text
make a decision
heavy rain
strong coffee
```

---

## Multi-word expression

Jednostka językowa składająca się z kilku słów, traktowana jako całość.

---

## Retention

Procent materiału, który użytkownik jest w stanie poprawnie odtworzyć.

---

## Retrievability

Szacowane prawdopodobieństwo przypomnienia sobie konkretnego elementu wiedzy w danym momencie.

---

## Stability

Szacowany czas, przez który dana informacja pozostaje stabilna w pamięci.

---

## Text Coverage

Procent wszystkich wystąpień słów w tekście, które użytkownik zna.

---

## Unique Vocabulary Coverage

Procent unikalnego słownictwa publikacji znanego użytkownikowi.

---

## Source of Truth

Główne, autorytatywne źródło danych.

W projekcie jest nim własna baza słownictwa użytkownika, a nie zewnętrzna aplikacja do fiszek.

---

## Local-first

Podejście, w którym dane użytkownika są przede wszystkim przechowywane lokalnie i mogą działać bez stałego połączenia z zewnętrzną usługą.

---

## PWA - Progressive Web App

Aplikacja webowa, którą można zainstalować na telefonie lub komputerze i która może zachowywać się podobnie do aplikacji natywnej.

---

# 46. Docelowa wizja

System powinien odpowiadać na trzy kluczowe pytania:

## 1. Co już znam?

```text
Known vocabulary:
8427 lemmas
```

## 2. Czego powinienem nauczyć się teraz?

```text
Next book:
76 high-value unknown words
```

## 3. Czego zaczynam zapominać?

```text
23 mature words need review
```

Docelowa pętla:

```text
REAL CONTENT
     ↓
DISCOVER UNKNOWN LANGUAGE
     ↓
LEARN
     ↓
REMEMBER
     ↓
READ
     ↓
NEXT CONTENT
```

Każda kolejna publikacja powiększa globalną bazę wiedzy użytkownika.

Z czasem liczba nowych słów przypadających na kolejne książki i artykuły powinna maleć, tworząc mierzalny obraz rozwoju znajomości języka.

Projekt nie jest więc tylko aplikacją do fiszek.

Jest to:

> **osobisty indeks znajomości języka budowany na podstawie wszystkiego, co użytkownik przeczytał.**
