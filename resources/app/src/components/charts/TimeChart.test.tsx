import { waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderAt } from '../../test/render';
import { TimeChart } from './TimeChart';

// A chart whose model does not exist yet, as ECharts' does between init and
// its first setOption: reading pixels from it throws, as the real one does
// ("Cannot read properties of undefined (reading 'queryComponents')").
const chart = vi.hoisted(() => ({
    on: vi.fn(),
    setOption: vi.fn(),
    getOption: vi.fn(() => undefined),
    getWidth: vi.fn(() => 400),
    isDisposed: vi.fn(() => false),
    convertToPixel: vi.fn(() => { throw new TypeError("Cannot read properties of undefined (reading 'queryComponents')"); }),
    dispatchAction: vi.fn(),
    resize: vi.fn(),
    dispose: vi.fn(),
}));

vi.mock('./echarts', () => ({ default: { init: () => chart } }));

describe('the time chart', () => {
    it('places no deploy markers on a chart that has not drawn yet', async () => {
        await renderAt(
            <TimeChart
                series={[{ name: 'requests', data: [[1735689600000, 1], [1735689660000, 2]] }]}
                annotations={[{ xAxis: 1735689630000, label: 'v1.2.0', kind: 'app.deployment', time: '' }]}
            />,
        );

        await waitFor(() => expect(chart.setOption).toHaveBeenCalled());
        expect(chart.convertToPixel).not.toHaveBeenCalled();
    });
});
