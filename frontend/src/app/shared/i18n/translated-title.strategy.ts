import {inject, Injectable} from '@angular/core';
import {Title} from '@angular/platform-browser';
import {RouterStateSnapshot, TitleStrategy} from '@angular/router';
import {TranslateService} from '@ngx-translate/core';

/** A route's `title` is a translation key (`titles.projects`); the page is titled "<text> · Izbik", or "Izbik" without one. */
@Injectable()
export class TranslatedTitleStrategy extends TitleStrategy {
    private readonly title = inject(Title);
    private readonly translate = inject(TranslateService);

    public override updateTitle(snapshot: RouterStateSnapshot): void {
        const key = this.buildTitle(snapshot);
        this.title.setTitle(key === undefined ? 'Izbik' : `${this.translate.instant(key) as string} · Izbik`);
    }
}
