import {readdirSync, readFileSync} from 'node:fs';
import {join, relative} from 'node:path';

import {
    parseTemplate,
    TmplAstElement,
    TmplAstRecursiveVisitor,
    TmplAstTemplate,
    TmplAstText,
    TmplAstTextAttribute,
    tmplAstVisitAll,
} from '@angular/compiler';

/** Attributes people read: a static one must be a translated binding instead. */
const ReadAttributes = new Set(['title', 'placeholder', 'aria-label', 'alt']);

/** Words a template may write as they are: key names, units, the brand, a shell command. */
const AllowedWords = new Set([
    'Alt', 'Backspace', 'Ctrl', 'Del', 'Enter', 'Esc', 'PgDn', 'PgUp', 'Shift', 'Izbik',
    'mm', 'px', 'x', 'y', 'glb', 'claude', 'mcp', 'add', 'transport', 'http', 'izbik',
]);

/** Developer tools, English on purpose. */
const SkippedFiles = new Set(['app/viewer/stats-hud.component.ts']);

class TextCollector extends TmplAstRecursiveVisitor {
    public readonly found: string[] = [];

    public override visitText(text: TmplAstText): void {
        this.check(text.value, 'text');
    }

    public override visitElement(element: TmplAstElement): void {
        this.checkAttributes(element.attributes);
        super.visitElement(element);
    }

    public override visitTemplate(template: TmplAstTemplate): void {
        this.checkAttributes(template.attributes);
        super.visitTemplate(template);
    }

    private checkAttributes(attributes: TmplAstTextAttribute[]): void {
        for (const attribute of attributes) {
            if (ReadAttributes.has(attribute.name)) {
                this.check(attribute.value, attribute.name);
            }
        }
    }

    private check(value: string, where: string): void {
        const words = value.match(/[A-Za-zÀ-ž]{2,}/g) ?? [];
        if (words.some((word) => !AllowedWords.has(word))) {
            this.found.push(`${where}: ${value.trim().replace(/\s+/g, ' ')}`);
        }
    }
}

function templates(directory: string): {file: string; template: string}[] {
    return readdirSync(directory, {withFileTypes: true}).flatMap((entry) => {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) {
            return templates(path);
        }
        if (entry.name.endsWith('.html')) {
            return [{file: path, template: readFileSync(path, 'utf8')}];
        }
        if (entry.name.endsWith('.ts') && !entry.name.endsWith('.spec.ts')) {
            const inline = /template:\s*`([\s\S]*?)`,\s*\n/.exec(readFileSync(path, 'utf8'));
            return inline === null ? [] : [{file: path, template: inline[1]}];
        }
        return [];
    });
}

function englishIn(template: string): string[] {
    const collector = new TextCollector();
    tmplAstVisitAll(collector, parseTemplate(template, 'sample.html', {preserveWhitespaces: false}).nodes);
    return collector.found;
}

describe('templates', () => {
    it('are checked for texts and read attributes, not for bindings, keys or units', () => {
        expect(englishIn(`
            <p>Save the view</p>
            @if (open) { <button [title]="'common.save' | translate" placeholder="Name">{{ 'common.save' | translate }}</button> }
            <kbd>Shift</kbd> <span>12 mm</span> <img alt="" [attr.aria-label]="label" />
        `)).toEqual(['text: Save the view', 'placeholder: Name']);
    });

    it('write no English of their own: every text a person reads is a translation key', () => {
        const source = join(process.cwd(), 'src');
        const found = templates(join(source, 'app'))
            .filter(({file}) => !SkippedFiles.has(relative(source, file)))
            .flatMap(({file, template}) => {
                return englishIn(template).map((text) => `${relative(source, file)} — ${text}`);
            });

        expect(found).toEqual([]);
    });
});
