#!/usr/bin/env python3
"""Flags wording in UAT page specs that reads as machine-written, so testers get plain instructions.

Usage:
    check-prose.py docs/uat/<slug>.json [...]

Checks every string a tester reads: em dashes, en dashes outside a number range, words that assert importance
without showing it, set phrases, throat-clearing openers, "not X, but Y" contrasts, English sentences over
30 words, the word "tutorial" in either language, Japanese filler words, Japanese sentences over 80
characters, and a Mac shortcut with no Windows one beside it. Exits 1 when anything is found.
"""

import json
import re
import sys
from pathlib import Path

WORDS = r'seamless|delve|vibrant|bustling|nestled|testament|holistic|synergy|tapestry|robust|leverage|elevate|crucial|unlock|effortless|streamlin|empower|cutting-edge|game-changer|comprehensive|intuitive|powerful'
PHRASES = [
    r'not just \w+,? but', r'more than just', r'dive into', r"in today's", r'pivotal', r'marks a shift',
    r'serves as', r'underscores', r"here's the thing", r"here's why", r"it'?s worth noting",
    r'it is worth noting', r'at the end of the day', r'when it comes to', r'in a world where', r'at its core',
    r'it is important to note', r'notably', r'to be clear',
    r'in plain terms', r'let me', r'as we.ll see', r'simply', r'just click', r'easily', r'ensure',
]
CONTRAST = r"\b(?:is not|isn't|are not|aren't) (?:a |an |the )?\w+(?: \w+)?, (?:but|it's|it is)\b"
MAX_WORDS = 30
JA_WORDS = r'チュートリアル|シームレス|包括的|直感的|非常に|簡単に|ぜひ|確実に|スムーズ|圧倒的|革新的|最適な'
MAX_JA_CHARS = 80


def strings(node, path='$'):
    """Yields (path, text) for every string in the spec a tester reads."""
    if isinstance(node, dict):
        for key, value in node.items():
            if key in ('id', 'slug', 'block', 'image'):
                continue
            yield from strings(value, f'{path}.{key}')
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from strings(value, f'{path}[{index}]')
    elif isinstance(node, str):
        yield path, node


def findings(text):
    plain = re.sub(r'<[^>]+>', '', text)
    if '—' in plain:
        yield 'em dash'
    for match in re.finditer(r'(.)–(.)', plain):
        if not (match.group(1).isdigit() and match.group(2).isdigit()):
            yield 'en dash outside a number range'
    for match in re.finditer(rf'\b({WORDS})\w*', plain, re.IGNORECASE):
        yield f'word: {match.group(0)}'
    for phrase in PHRASES:
        if re.search(rf'\b{phrase}', plain, re.IGNORECASE):
            yield f'phrase: {phrase}'
    if re.search(CONTRAST, plain, re.IGNORECASE):
        yield 'contrast: "is not X, but Y"'
    if re.search(r'\btutorial', plain, re.IGNORECASE):
        yield 'word: tutorial'
    for match in re.finditer(JA_WORDS, plain):
        yield f'word: {match.group(0)}'
    if re.search(r'Command|⌘|\bOption\b', plain) and 'Ctrl' not in plain:
        yield 'Mac shortcut with no Windows one'
    for sentence in re.split(r'(?<=[.!?])\s+', plain):
        latin = re.sub(r'[^\x00-\x7f]+', ' X ', sentence)
        if len(latin.split()) > MAX_WORDS:
            yield f'sentence of {len(latin.split())} words: {sentence[:60]}…'
    for sentence in re.split(r'(?<=。)', plain):
        if re.search(r'[぀-ヿ]', sentence) and len(sentence) > MAX_JA_CHARS:
            yield f'Japanese sentence of {len(sentence)} characters: {sentence[:30]}…'


def main():
    found = 0
    for name in sys.argv[1:] or sys.exit(__doc__):
        for path, text in strings(json.loads(Path(name).read_text())):
            for finding in findings(text):
                found += 1
                print(f'{name} {path}: {finding}')
    sys.exit(1 if found else 0)


if __name__ == '__main__':
    main()
