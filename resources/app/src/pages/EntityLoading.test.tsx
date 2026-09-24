import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderAt } from '../test/render';
import { EntityView } from './EntityPages';

afterEach(() => vi.unstubAllGlobals());

describe('an entity page on a slow trace store', () => {
    it('says what it is reading while the story has not arrived, with the header already up', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>(() => {})));

        await renderAt(<EntityView type="route" />, '/entity/route?value=%2F*%2Fgeocode&period=1h');

        expect(await screen.findByText("Reading this route's requests from the trace store…")).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: '/*/geocode' })).toBeInTheDocument();
    });
});
