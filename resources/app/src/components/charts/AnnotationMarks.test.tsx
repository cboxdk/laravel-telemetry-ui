import { describe, expect, it } from 'vitest';
import type { Annotation } from '../../api/types';
import { groupMarks } from './AnnotationMarks';

const a = (label: string): Annotation => ({ xAxis: 0, label, kind: 'app.deployment', time: '' });

describe('groupMarks', () => {
    it('keeps markers apart when their flags would not touch', () => {
        expect(groupMarks([{ x: 100, a: a('one') }, { x: 40, a: a('two') }]).map((g) => [g.x, g.items.map((i) => i.label)]))
            .toEqual([[40, ['two']], [100, ['one']]]);
    });

    it('folds markers that would overlap into one flag, anchored at the first', () => {
        // A deploy and the cache purge it triggered, seconds apart on a week-wide chart.
        const groups = groupMarks([{ x: 200, a: a('deploy') }, { x: 205, a: a('purge') }, { x: 260, a: a('later') }]);
        expect(groups.map((g) => [g.x, g.items.length])).toEqual([[200, 2], [260, 1]]);
    });
});
