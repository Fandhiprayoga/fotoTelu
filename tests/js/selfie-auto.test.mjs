import { test } from 'node:test';
import assert from 'node:assert/strict';
import { StableFace } from '../../public/assets/js/selfie-auto.mjs';

const face = (x = 200) => ({ boundingBox: { originX: x, originY: 110, width: 240, height: 260 } });
const check = (tracker, faces, time) => tracker.check(faces, 640, 480, time);

test('one centered face must remain stable for two seconds', () => {
    const tracker = new StableFace();
    for (let time = 0; time < 2000; time += 250) assert.equal(check(tracker, [face()], time).capture, false);
    assert.equal(check(tracker, [face()], 2000).capture, true);
});

test('zero/multiple faces, lost frames, and movement reset stability', () => {
    for (const interruption of [[], [face(), face()], [face(270)]]) {
        const tracker = new StableFace();
        for (let time = 0; time <= 1750; time += 250) check(tracker, [face()], time);
        assert.equal(check(tracker, interruption, 2000).capture, false);
        assert.equal(check(tracker, [face()], 2250).capture, false);
    }
    const tracker = new StableFace();
    check(tracker, [face()], 0);
    assert.equal(check(tracker, [face()], 3000).capture, false);
});

test('bad geometry, off-center and small faces never trigger capture', () => {
    for (const box of [null, {originX: NaN}, {originX: 0, originY: 0, width: 80, height: 80},
        {originX: 0, originY: 80, width: 240, height: 260}, {originX: 0, originY: 0, width: 640, height: 480}]) {
        const tracker = new StableFace();
        for (let time = 0; time <= 3000; time += 250) {
            assert.equal(check(tracker, [{boundingBox: box}], time).capture, false);
        }
    }
});