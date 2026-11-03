// Generates the README header with OpenAI's image API.
//
//   zsh -lic 'node scripts/generate-header.mjs [--attempt N]'
//
// Reads OPENAI_API_KEY from the environment (never logged). Writes art/header.a<N>.png;
// pick one, crop it to a 3:1 band and save it as art/header.png.
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const model = process.env.OPENAI_IMAGE_MODEL ?? 'gpt-image-2';
const attempt = process.argv.includes('--attempt') ? process.argv[process.argv.indexOf('--attempt') + 1] : '1';

const prompt = [
  'A wide, minimal README header banner for a software package. Flat editorial graphic design, no photo realism.',
  'Background: deep aubergine purple (#3F0E40) with a very subtle paper grain.',
  'Keep everything inside a horizontal band across the vertical middle of the image; the top and bottom fifth are plain background.',
  'Left side: the word "SLACKCACHE" set in a huge, heavy, condensed grotesque sans-serif, off-white (#F4EDE4), tight letter spacing.',
  'Directly below it, smaller, in a clean sans-serif, off-white: "Laravel cache. Eviction policy: the free plan."',
  'Right side: a tidy isometric server rack built from a stack of rounded chat message bubbles, in off-white, Laravel red (#FF2D20) and muted teal,',
  'each bubble carrying a tiny row of monospace key glyphs; one bubble at the top is gently fading away into dotted outline, as if being evicted.',
  'Generous negative space, crisp vector edges, no logos, no extra text, no watermark.',
].join(' ');

const key = process.env.OPENAI_API_KEY;
if (!key) throw new Error('OPENAI_API_KEY is not set (run through zsh -lic)');

const res = await fetch('https://api.openai.com/v1/images/generations', {
  method: 'POST',
  headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
  body: JSON.stringify({ model, prompt, size: '1536x1024', quality: 'high', n: 1 }),
  signal: AbortSignal.timeout(360_000),
});
if (!res.ok) throw new Error(`OpenAI ${res.status}: ${(await res.text()).slice(0, 300)}`);
const json = await res.json();
const out = join(root, `art/header.a${attempt}.png`);
await mkdir(dirname(out), { recursive: true });
await writeFile(out, Buffer.from(json.data[0].b64_json, 'base64'));
console.log(`wrote ${out}`);
