import {registerLocaleData} from '@angular/common';
import localeCs from '@angular/common/locales/cs';
import {levelText} from '@app/core/level-text';
import {decimalSeparator, fixed} from '@app/core/number-format';

describe('number formats', () => {
    it('take each locale\'s decimal separator', () => {
        registerLocaleData(localeCs);
        expect([decimalSeparator('en'), decimalSeparator('cs')]).toEqual(['.', ',']);
        expect([fixed(2.5, 2, ','), fixed(2.5, 2, '.')]).toEqual(['2,50', '2.50']);
        expect([levelText(3000), levelText(-150, ','), levelText(0, ',')]).toEqual(['+3.000', '−0,150', '±0,000']);
    });
});
