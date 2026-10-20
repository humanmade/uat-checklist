#!/usr/bin/env python3
"""Builds a checklist, and its UAT page, on the site UAT_URL names, from its spec in docs/uat/<slug>.json.

Usage:
    build-page.py docs/uat/<slug>.json [--shots DIR] [--no-upload] [--checklist-only] [--dry-run]

Writes the spec's checklist as a checklist post with the spec's slug, which the plugin's panel lists on every
screen. Unless --checklist-only is given, it also writes the page, under the hub page when the site has one
(UAT_HUB_SLUG, default `hub`) and at the top level otherwise, with Yoast's noindex set when Yoast is active, and
names the page as the checklist's instructions page. --checklist-only leaves any page alone and keeps whatever
instructions page the checklist already names. The spec's "language" must match the site's locale.

Screenshots named in the spec are read from DIR (default <cache>/shots/<slug>/) and uploaded as
uat-<slug>-<name>.png, replacing an earlier upload only when the file has changed. --no-upload uploads nothing
and uses the uat-<slug>-<name>.png files already in the Media Library, for a host the screenshots are uploaded to
by hand. A step without an "id" is given one, and the spec is saved, so its results survive later edits to its
wording. --dry-run prints the page's markup and writes nothing, neither to the site nor to the spec.

Environment: UAT_URL (required), UAT_WP (WP-CLI command, default `wp`), UAT_HUB_SLUG, UAT_CACHE.
"""

import argparse
import base64
import hashlib
import json
import re
import subprocess
import sys
import uuid
from html import escape
from pathlib import Path

from config import CACHE, HUB_SLUG, SITE_PATH, UAT_URL, wp_command

CHECKLIST_NAMESPACE = 'uat-checklist'
# The builder's section headings, by language. A site in any other language sets "headings" in each spec.
SECTION_HEADINGS = {
    'en': {'tutorial': 'How to use the block', 'examples': 'Live examples'},
    'ja': {'tutorial': 'ブロックの使い方', 'examples': '表示例'},
}
NO_DESIGN_LINK = {
    'en': ' (design link not added yet)',
    'ja': '（デザインのリンクはまだ追加されていません）',
}
DESIGN_TOKEN = re.compile(r'<a href="\{design:(\w+)\}">(.*?)</a>')
DESIGN_URL = re.compile(r'https://[^\s"<>]+')
# Classes the checklist plugin's src/guide.scss styles: the how-to, its numbered steps, and a screenshot the text
# wraps round.
GUIDE = 'uat-guide'
HOWTO = 'uat-howto'
SIDE_FIGURE = 'uat-figure-side'

# Runs inside WordPress through `wp eval`, reading one JSON request from stdin.
PHP = r'''
$request = json_decode( stream_get_contents( STDIN ), true );

if ( $request['action'] === 'site' ) {
	$hub = $request['hub_slug'] === '' ? null : get_page_by_path( $request['hub_slug'] );
	echo wp_json_encode(
		[
			'locale' => get_locale(),
			'hub'    => $hub && $hub->post_status === 'publish' ? $request['hub_slug'] : '',
		]
	);
	return;
}

if ( $request['action'] === 'media' ) {
	$lookup = [
		'post_type'        => 'attachment',
		'post_status'      => 'inherit',
		'posts_per_page'   => 1,
		'suppress_filters' => false,
	];
	// By this script's own marker; by stored path for an upload made by hand. Not by slug or file name, which
	// WordPress suffixes with -1 while the upload being replaced still holds the name.
	$found  = get_posts( $lookup + [ 'meta_key' => '_uat_source', 'meta_value' => $request['name'] ] )
		?: get_posts(
			$lookup + [
				'meta_query' => [
					[
						'key'     => '_wp_attached_file',
						'value'   => $request['name'] . '.png',
						'compare' => 'LIKE',
					],
				],
			]
		);
	$old    = $found[0] ?? null;

	if (
		$old
		&& ( $request['b64'] === null || get_post_meta( $old->ID, '_uat_source_md5', true ) === $request['md5'] )
	) {
		$id = $old->ID;
	} elseif ( $request['b64'] === null ) {
		echo wp_json_encode( [ 'missing' => $request['name'] . '.png' ] );
		return;
	} else {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( $request['name'] );
		file_put_contents( $tmp, base64_decode( $request['b64'] ) );
		$id = media_handle_sideload(
			[ 'name' => $request['name'] . '.png', 'tmp_name' => $tmp ],
			(int) $request['parent']
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id );
		}
		update_post_meta( $id, '_uat_source_md5', $request['md5'] );
		if ( $old ) {
			wp_delete_attachment( $old->ID, true );
		}
	}

	update_post_meta( $id, '_uat_source', $request['name'] );
	update_post_meta( $id, '_wp_attachment_image_alt', $request['alt'] );
	echo wp_json_encode( [ 'id' => $id, 'url' => wp_get_attachment_url( $id ) ] );
	return;
}

if ( $request['action'] === 'resolve' ) {
	// {media:<slug>}, {media-url:<slug>} and {post:<slug>} placeholders: an attachment or post found by its slug,
	// so a spec names it the same way on every environment.
	$found = [];
	foreach ( $request['names'] as $name ) {
		$attachment = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'name'             => $name,
				'posts_per_page'   => 1,
				'suppress_filters' => false,
			]
		)[0] ?? null;
		if ( ! $attachment ) {
			WP_CLI::error( 'No media with the slug ' . $name );
		}
		$found[ $name ] = [ 'id' => $attachment->ID, 'url' => wp_get_attachment_url( $attachment->ID ) ];
	}
	foreach ( $request['posts'] as $name ) {
		$post = get_posts(
			[
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'name'             => $name,
				'posts_per_page'   => 1,
				'suppress_filters' => false,
			]
		)[0] ?? null;
		if ( ! $post ) {
			WP_CLI::error( 'No published post with the slug ' . $name );
		}
		$found[ 'post:' . $name ] = [ 'id' => $post->ID ];
	}
	echo wp_json_encode( $found );
	return;
}

if ( $request['action'] === 'checklist' ) {
	// Found by slug in any status but the trash, so a rebuild updates the checklist its results belong to.
	$found = get_posts(
		[
			'post_type'        => 'uat_checklist',
			'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'name'             => $request['slug'],
			'posts_per_page'   => 1,
			'suppress_filters' => false,
		]
	)[0] ?? null;
	$post  = [
		'post_type'    => 'uat_checklist',
		'post_status'  => 'publish',
		'post_title'   => $request['title'],
		'post_name'    => $request['slug'],
		'post_content' => $request['content'],
	];
	if ( $found ) {
		$post['ID'] = $found->ID;
	}
	$id = $found ? wp_update_post( wp_slash( $post ), true ) : wp_insert_post( wp_slash( $post ), true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id );
	}
	if ( $request['page'] !== null ) {
		update_post_meta( $id, '_uat_instructions_page', (int) $request['page'] );
	}
	echo wp_json_encode( [ 'id' => $id, 'edit' => admin_url( 'post.php?post=' . $id . '&action=edit' ) ] );
	return;
}

$parent = $request['parent_slug'] === '' ? null : get_page_by_path( $request['parent_slug'] );
if ( $request['parent_slug'] !== '' && ! $parent ) {
	WP_CLI::error( 'No "' . $request['parent_slug'] . '" page to put the UAT page under.' );
}

$page = get_page_by_path( $parent ? $request['parent_slug'] . '/' . $request['slug'] : $request['slug'] );

if ( $request['action'] === 'locate' ) {
	echo wp_json_encode( [ 'parent' => $parent ? $parent->ID : 0, 'page' => $page ? $page->ID : 0 ] );
	return;
}

$post = [
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => $request['title'],
	'post_name'    => $request['slug'],
	'post_parent'  => $parent ? $parent->ID : 0,
	'post_content' => $request['content'],
];
if ( $page ) {
	$post['ID'] = $page->ID;
}
$id = $page ? wp_update_post( wp_slash( $post ), true ) : wp_insert_post( wp_slash( $post ), true );
if ( is_wp_error( $id ) ) {
	WP_CLI::error( $id );
}
if ( defined( 'WPSEO_VERSION' ) ) {
	update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' );
}
echo wp_json_encode( [ 'id' => $id, 'url' => get_permalink( $id ) ] );
'''


def wp(request):
    """Sends one request to the PHP above through WP-CLI and returns its JSON reply."""
    done = subprocess.run(
        [*wp_command(), 'eval', PHP],
        input=json.dumps(request, ensure_ascii=False), capture_output=True, text=True,
    )
    reply = done.stdout.strip().splitlines()[-1] if done.stdout.strip() else ''
    if done.returncode != 0 or not reply.startswith('{'):
        sys.exit(f'WP-CLI failed: {done.stdout}{done.stderr}')
    return json.loads(reply)


def block_attrs(attrs):
    """Serialises block attributes the way core's serialize_block_attributes() does."""
    out = json.dumps(attrs, ensure_ascii=False, separators=(',', ':'))
    for raw, escaped in (
        ('--', '\\u002d\\u002d'),
        ('<', '\\u003c'),
        ('>', '\\u003e'),
        ('&', '\\u0026'),
        ('\\"', '\\u0022'),
    ):
        out = out.replace(raw, escaped)
    return out


def heading(text, level):
    attrs = '' if level == 2 else f' {{"level":{level}}}'
    return f'<!-- wp:heading{attrs} -->\n<h{level} class="wp-block-heading">{text}</h{level}>\n<!-- /wp:heading -->'


def paragraph(text):
    return f'<!-- wp:paragraph -->\n<p>{text}</p>\n<!-- /wp:paragraph -->'


def listing(items, ordered):
    tag, attrs = ('ol', ' {"ordered":true}') if ordered else ('ul', '')
    lis = ''.join(f'<!-- wp:list-item -->\n<li>{item}</li>\n<!-- /wp:list-item -->' for item in items)
    return f'<!-- wp:list{attrs} -->\n<{tag} class="wp-block-list">{lis}</{tag}>\n<!-- /wp:list -->'


def image(node, media):
    """An image block, not linked. `"float": "right"` sets it beside the text that follows, which wraps round it."""
    mid, url = media[node['image']]
    attrs = {'id': mid, 'sizeSlug': 'full', 'linkDestination': 'none'}
    classes = 'wp-block-image size-full'
    if node.get('float') == 'right':
        attrs.update(align='right', className=SIDE_FIGURE)
        classes = f'wp-block-image alignright size-full {SIDE_FIGURE}'
    caption = f'<figcaption class="wp-element-caption">{node["caption"]}</figcaption>' if node.get('caption') else ''
    return (
        f'<!-- wp:image {block_attrs(attrs)} -->\n'
        f'<figure class="{classes}"><img src="{url}" alt="{escape(node["alt"])}" class="wp-image-{mid}"/>'
        f'{caption}</figure>\n<!-- /wp:image -->'
    )


def group(class_name, inner):
    return (
        f'<!-- wp:group {{"className":"{class_name}","layout":{{"type":"default"}}}} -->\n'
        f'<div class="wp-block-group {class_name}">{inner}</div>\n<!-- /wp:group -->'
    )


def howto_steps(steps, media):
    """Numbered steps, each its text and an optional screenshot under it. The stylesheet numbers them."""
    parts = []
    for step in steps:
        # A step's screenshot sits on its own line under the text, never floated beside it.
        shot = image({**step, 'float': None}, media) if 'image' in step else ''
        parts.append(group(f'{HOWTO}__step', paragraph(step['text']) + shot))
    return group(HOWTO, '\n'.join(parts))


def node_markup(node, media):
    """Returns one content node's block markup. references/spec-format.md lists the node types."""
    if 'h2' in node:
        return heading(node['h2'], 2)
    if 'h3' in node:
        return heading(node['h3'], 3)
    if 'p' in node:
        return paragraph(node['p'])
    if 'ul' in node or 'ol' in node:
        return listing(node.get('ul') or node['ol'], 'ol' in node)
    if 'image' in node:
        return image(node, media)
    if 'steps' in node:
        return howto_steps(node['steps'], media)
    if 'images' in node:
        columns = ''.join(
            f'<!-- wp:column -->\n<div class="wp-block-column">{image(item, media)}</div>\n<!-- /wp:column -->'
            for item in node['images']
        )
        return f'<!-- wp:columns -->\n<div class="wp-block-columns">{columns}</div>\n<!-- /wp:columns -->'
    if 'block' in node:
        return node['block']
    sys.exit(f'Unknown content node: {node}')


def step_attrs(step, media):
    """A step's block attributes. A scenario step carries `instructions`, and a list in `expected` becomes `expectedItems`."""
    attrs = {'stepId': step['id'], 'step': step['step']}
    if isinstance(step['expected'], list):
        attrs['expectedItems'] = step['expected']
    else:
        attrs['expected'] = step['expected']
    if step.get('instructions'):
        attrs['instructions'] = step['instructions']
    if step.get('image'):
        attrs.update(imageUrl=media[step['image']][1], imageAlt=step['alt'])
    # A step is required unless the spec marks it nice to have.
    if step.get('optional'):
        attrs['required'] = False
    return attrs


def checklist(intro, sections, media):
    ns = CHECKLIST_NAMESPACE
    parts = [paragraph(node['p']) for node in intro]
    for section in sections:
        steps = ''.join(f'<!-- wp:{ns}/step {block_attrs(step_attrs(s, media))} /-->\n' for s in section['steps'])
        parts.append(
            f'<!-- wp:{ns}/section {block_attrs({"title": section["section"]})} -->\n'
            f'{steps}<!-- /wp:{ns}/section -->'
        )
    return f'<!-- wp:{ns}/checklist -->\n' + '\n'.join(parts) + f'\n<!-- /wp:{ns}/checklist -->'


def resolve_media(markup):
    """Replaces {media:<slug>} with an attachment's ID, {media-url:<slug>} with its URL and {post:<slug>} with a post's ID."""
    names = sorted(set(re.findall(r'\{media(?:-url)?:([\w-]+)\}', markup)))
    posts = sorted(set(re.findall(r'\{post:([\w-]+)\}', markup)))
    if not names and not posts:
        return markup
    found = wp({'action': 'resolve', 'names': names, 'posts': posts})
    markup = re.sub(r'\{media:([\w-]+)\}', lambda m: str(found[m.group(1)]['id']), markup)
    markup = re.sub(r'\{post:([\w-]+)\}', lambda m: str(found['post:' + m.group(1)]['id']), markup)
    return re.sub(r'\{media-url:([\w-]+)\}', lambda m: found[m.group(1)]['url'], markup)


def design_links(spec_path, slug, language):
    """The page's design links from design-links.json beside the spec, by key. Stops on a value that is not a URL."""
    path = spec_path.resolve().parent / 'design-links.json'
    links = json.loads(path.read_text()).get(slug, {}).get(language, {}) if path.exists() else {}
    wrong = [key for key, url in links.items() if url and not DESIGN_URL.fullmatch(url)]
    if wrong:
        sys.exit(f'{path}: {slug}.{language}.{", ".join(wrong)} must be an https:// link with no spaces or quotes')
    return links


def resolve_design(node, links, language):
    """Points each <a href="{design:<key>}"> at the page's design link, or says in the step that it is missing."""
    if isinstance(node, dict):
        return {key: resolve_design(value, links, language) for key, value in node.items()}
    if isinstance(node, list):
        return [resolve_design(value, links, language) for value in node]
    if isinstance(node, str):
        missing = NO_DESIGN_LINK.get(language, NO_DESIGN_LINK['en'])
        return DESIGN_TOKEN.sub(
            lambda m: (
                f'<a href="{links[m.group(1)]}">{m.group(2)}</a>'
                if links.get(m.group(1))
                else m.group(2) + missing
            ),
            node,
        )
    return node


def section_headings(spec, language):
    """The page's headings, from the spec's own "headings" first and the builder's table for the language second."""
    headings = {**SECTION_HEADINGS.get(language, {}), **spec.get('headings', {})}
    missing = [key for key in SECTION_HEADINGS['en'] if key not in headings]
    if missing:
        sys.exit(f'No built-in headings for "{language}": set "headings" with {", ".join(missing)} in the spec.')
    return headings


def site_paths(markup, hub):
    """Replaces {pages} with the path the UAT pages sit under, the hub's or the site's, and {site} with the site's."""
    pages = f'{SITE_PATH}/{hub}' if hub else SITE_PATH
    return markup.replace('{pages}', pages).replace('{site}', SITE_PATH)


def page_markup(spec, media, language, hub):
    lead = [paragraph(spec['subtitle'])] if spec.get('subtitle') else []
    if spec.get('hub') or spec.get('reference'):
        nodes = [node_markup(node, media) for node in spec['content']]
        # A reference page is reading matter, like a how-to, so it takes the guide's measure.
        blocks = lead + ([group(GUIDE, '\n'.join(nodes))] if spec.get('reference') else nodes)
        return resolve_media(site_paths('\n\n'.join(blocks), hub))
    blocks = lead
    for key, title in section_headings(spec, language).items():
        blocks.append(heading(title, 2))
        nodes = [node_markup(node, media) for node in spec.get(key, [])]
        # The how-to is reading matter, so it takes the guide's measure and figure frames. The examples stay
        # outside it, at the width the blocks render at on the site.
        blocks += [group(GUIDE, '\n'.join(nodes))] if key == 'tutorial' and nodes else nodes
    return resolve_media(site_paths('\n\n'.join(blocks), hub))


def checklist_markup(spec, media, hub):
    """The checklist post's content: the Testing checklist block, its intro paragraphs above its sections."""
    intro = spec.get('checklist_intro', [])
    # The checklist block takes paragraphs and sections only, so any other node would be refused by the editor.
    if any(list(node) != ['p'] for node in intro):
        sys.exit('"checklist_intro" holds paragraphs only: write each node as {"p": "…"}.')
    return resolve_media(site_paths(checklist(intro, spec['checklist'], media), hub))


def image_nodes(spec, checklist_only=False):
    """Every node that names a screenshot: content, how-to steps, side-by-side images and checklist steps."""
    keys = () if checklist_only else ('content', *SECTION_HEADINGS['en'])
    nodes = [n for key in keys for n in spec.get(key, [])]
    nodes += [s for section in spec.get('checklist', []) for s in section['steps']]
    return [item for n in nodes for item in n.get('images', n.get('steps', [n])) if 'image' in item]


def upload_images(spec, shots, parent_id, upload, dry_run, checklist_only=False):
    """Returns {name: (id, url)} for every screenshot, uploading only those that changed. Stops naming any missing."""
    media, missing = {}, []
    for node in image_nodes(spec, checklist_only):
        if node['image'] in media:
            continue
        path = shots / f'{node["image"]}.png'
        data = path.read_bytes() if upload and path.exists() and not dry_run else None
        reply = wp({
            'action': 'media',
            'name': f'uat-{spec["slug"]}-{node["image"]}',
            'alt': node['alt'],
            'parent': parent_id,
            'b64': base64.b64encode(data).decode() if data else None,
            'md5': hashlib.md5(data).hexdigest() if data else None,
        })
        if 'missing' in reply:
            missing.append((reply['missing'], path))
            media[node['image']] = (0, '')
            continue
        media[node['image']] = (reply['id'], reply['url'])
    if missing and not dry_run:
        lines = '\n'.join(f'  {name}   (taken as {path})' for name, path in missing)
        sys.exit(f'Not in the Media Library yet. Upload each under this exact file name, then build again:\n{lines}')
    return media


def check_language(spec_path, spec, locale):
    """Stops before writing when the spec is written for another language than the site's."""
    language = spec.get('language', 'en')
    if not locale.lower().replace('-', '_').startswith(language.lower()):
        sys.exit(
            f'{spec_path} is written in "{language}", but {UAT_URL} is in {locale}. '
            'Write a spec in the site\'s language.'
        )
    return language.split('_')[0].lower()


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('spec', type=Path)
    parser.add_argument('--shots', type=Path)
    parser.add_argument('--no-upload', action='store_true')
    parser.add_argument('--checklist-only', action='store_true')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()

    spec = json.loads(args.spec.read_text())
    if args.checklist_only and (spec.get('hub') or spec.get('reference')):
        sys.exit('The hub and reference pages have no checklist to build on its own.')
    site = wp({'action': 'site', 'hub_slug': HUB_SLUG})
    language = check_language(args.spec, spec, site['locale'])
    shots = args.shots or CACHE / 'shots' / spec['slug']

    parent_slug = '' if spec.get('hub') else site['hub']
    if not args.checklist_only and not spec.get('hub') and HUB_SLUG and not site['hub']:
        print(f'No published "{HUB_SLUG}" page, so the page is built at the top level.', file=sys.stderr)

    missing = [s for section in spec.get('checklist', []) for s in section['steps'] if not s.get('id')]
    for step in missing:
        step['id'] = str(uuid.uuid4())
    if missing and not args.dry_run:
        args.spec.write_text(json.dumps(spec, ensure_ascii=False, indent='\t') + '\n')
        print(
            f'Gave {len(missing)} new steps an id and saved {args.spec}. Commit the ids with the spec.',
            file=sys.stderr,
        )

    # After the ids are saved, so the spec on disk keeps its {design:<key>} tokens.
    spec = resolve_design(spec, design_links(args.spec, spec['slug'], language), language)

    located = (
        {'page': 0, 'parent': 0}
        if args.checklist_only
        else wp({'action': 'locate', 'parent_slug': parent_slug, 'slug': spec['slug']})
    )
    media = upload_images(
        spec, shots, located['page'] or located['parent'], not args.no_upload, args.dry_run, args.checklist_only,
    )
    # The hub links to the pages under itself, which on a new site it is the first to exist.
    hub = spec['slug'] if spec.get('hub') else site['hub']
    has_checklist = bool(spec.get('checklist')) and not spec.get('hub') and not spec.get('reference')
    content = '' if args.checklist_only else page_markup(spec, media, language, hub)
    checklist_content = checklist_markup(spec, media, hub) if has_checklist else ''

    if args.dry_run:
        print('\n\n'.join(part for part in (content, checklist_content) if part))
        return

    page_id = None
    if not args.checklist_only:
        page = wp({
            'action': 'page', 'parent_slug': parent_slug, 'slug': spec['slug'], 'title': spec['title'],
            'content': content,
        })
        page_id = page['id']
        print(f'Page {page["id"]}: {page["url"]}')

    if has_checklist:
        built = wp({
            'action': 'checklist', 'slug': spec['slug'], 'title': spec['title'],
            'content': checklist_content, 'page': page_id,
        })
        print(f'Checklist {built["id"]}: {built["edit"]}')


if __name__ == '__main__':
    main()
