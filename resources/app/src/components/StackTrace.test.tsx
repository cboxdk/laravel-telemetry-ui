import { describe, expect, it } from 'vitest';
import { parseFrames } from './StackTrace';

const trace = [
    '#0 /srv/app/vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php(46): App\\Http\\Controllers\\DemoController->error()',
    '#1 /srv/app/app/Http/Middleware/Tenant.php(22): Illuminate\\Pipeline\\Pipeline->handle()',
    '#2 {main}',
].join('\n');

describe('stacktrace frames', () => {
    it('parses frames, marks vendor ones and strips the project root', () => {
        const frames = parseFrames(trace);
        expect(frames[0]).toMatchObject({ n: '0', vendor: true, line: '46', file: 'vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php' });
        expect(frames[1]).toMatchObject({ n: '1', vendor: false, file: 'app/Http/Middleware/Tenant.php' });
        expect(frames[2]).toMatchObject({ n: '', call: '#2 {main}' });
    });
});
