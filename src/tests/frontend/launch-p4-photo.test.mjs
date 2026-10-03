// LAUNCH-P4 B5 — photo upload (work order LAUNCH-P4, Part B):
//   upload, resize in the browser (max 800 px, JPEG, about 300 KB or less),
//   store on our own host; thumbnails in the catalogue list (initials when
//   there is no photo); the product, combo and category forms use the upload
//   and keep the link field as a fallback; English AND Arabic.
// Run: node --test tests/frontend/launch-p4-photo.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, lib, read, sfc } from './launch-p4-support.mjs';

test('B5 photos are resized to 800 px at most, keeping the shape, never upscaled', () => {
    const { fitWithin, qualitySteps, MAX_SIDE, TARGET_BYTES } = lib('imageResize');
    assert.equal(MAX_SIDE, 800);
    assert.equal(TARGET_BYTES, 300 * 1024);
    assert.deepEqual({ ...fitWithin(4000, 3000) }, { width: 800, height: 600 });
    assert.deepEqual({ ...fitWithin(1080, 1920) }, { width: 450, height: 800 });
    assert.deepEqual({ ...fitWithin(640, 480) }, { width: 640, height: 480 });
    const steps = [...qualitySteps()];
    assert.ok(steps.every((q, i) => i === 0 || q < steps[i - 1]), 'qualities go down');
    const source = read('resources/js/lib/imageResize.ts');
    assert.match(source, /'image\/jpeg'/);
});

test('B5 the upload field uploads the resized photo and keeps the link as a fallback', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ImageUploadField.vue');
    assert.match(script, /resizeToJpeg\(file\)/);
    assert.match(script, /uploadCatalogueImage\(small, props\.kind\)/);
    assert.match(template, /data-test="photo-input"/);
    assert.match(template, /data-test="photo-link"/);
    assertKeysExist(template + script, 'ImageUploadField');
    const api = read('resources/js/lib/api/catalogue.ts');
    assert.match(api, /apiUpload<\{ data: \{ url: string; path: string \} \}>\('\/api\/catalogue\/images', form\)/);
});

test('B5 the product, combo and category forms use the upload', () => {
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue').template, /<ImageUploadField v-model="form\.image_url" kind="product"/);
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/ComboEditor.vue').template, /<ImageUploadField v-model="form\.image_url" kind="product"/);
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/Index.vue').template, /<ImageUploadField v-model="catForm\.image_url" kind="category"/);
});

test('B5 the catalogue list shows a thumbnail, or the initials without a photo', () => {
    const { initialsOf } = lib('initials');
    assert.equal(initialsOf('Chicken Shawarma Wrap'), 'CS');
    assert.equal(initialsOf('latte'), 'L');
    assert.equal(initialsOf('شاي كرك'), 'شك');
    assert.equal(initialsOf(''), '?');
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/Index.vue').template, /<ProductThumb :url="prod\.image_url" :name="prod\.name" \/>/);
    const thumb = sfc('resources/js/Pages/Merchant/Catalogue/ProductThumb.vue');
    assert.match(thumb.template, /initialsOf\(name\)/);
    assert.doesNotMatch(thumb.template + thumb.script, /unsplash|coffee/i);
});

test('B5 every photo text exists in English and Arabic', () => {
    assertBilingual('photo');
});
