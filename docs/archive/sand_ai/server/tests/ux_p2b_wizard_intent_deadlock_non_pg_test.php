<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// UX P2B D2 contract checks that do not need PostgreSQL:
// acceptance round A6 found a hard deadlock: after a verify result went
// ambiguous in the UI but actually succeeded server-side, read-back recovery
// reported state=tested and told the user to click 「继续开通」 — but that
// button lives inside `showModelStep` (requires !intent) and `canActivate`
// returns false whenever an intent lock exists, so the promised action was
// never renderable.
//
// This test pins the fix shape:
// 1) a dedicated「继续开通」section renders under the intent lock for the
//    recovered continuable phases (saved/tested/published);
// 2) canActivate lets continuable intents through while every *_unknown
//    phase, busy, storage error, ambiguity and conflict still block;
// 3) continuing reuses activate() and the same save/verify/publish/bind
//    endpoints — no new mutating call, no resend of confirmed requests;
// 4) recovery notices that promise「继续开通」match phases the button covers;
// 5) save_unknown recovery treats SAND_AI_RESOURCE_NOT_FOUND (server-side
//    proof the original save never landed) like the not_submitted proof and
//    unlocks, while DRIFTED/CONFLICT/unreadable outcomes keep the lock;
// 6) intended safety locks stay locked: verify_unknown/publish_unknown/
//    bind_unknown/verification_ambiguous never get a resend path.

$root = dirname(__DIR__);
$frontend = dirname($root) . '/sandadmin-artd/src/views/plugin/sand-ai';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('UX P2B wizard intent-deadlock check failed: ' . $label);
    }
    ++$checks;
};

$homePath = $frontend . '/home/index.vue';
$assert(is_file($homePath), 'home component exists');
$home = (string) file_get_contents($homePath);

// ==== A: the「继续开通」action is renderable under the intent lock ====

$assert(str_contains($home, 'v-if="intentContinuable"'), 'continue section renders for continuable intents');
$assert(substr_count($home, '@click="activate"') >= 2, 'activate button exists for both fresh and resumed flows');
$assert(str_contains($home, "intent.value ? '继续开通' : '设为默认并启用'"), 'activateLabel already labels the resumed flow');
$assert(str_contains($home, "v-permission=\"'sand_ai:onboarding:publish'\""), 'continue button keeps the publish permission gate');

// The continue section must NOT sit inside the model step (which requires !intent).
$modelStepPos = strpos($home, 'v-if="showModelStep"');
$continuePos = strpos($home, 'v-if="intentContinuable"');
$assert($modelStepPos !== false && $continuePos !== false && $continuePos < $modelStepPos,
    'continue section is rendered before/independent of the !intent model step');

// ==== B: canActivate gates ====

$assert(str_contains($home, "['saved', 'tested', 'published'].includes(intent.value.phase)"),
    'continuable phases are exactly saved/tested/published');
$assert(str_contains($home, 'if (intent.value) return intentContinuable.value'),
    'canActivate admits only continuable intents');

// Scope the remaining gate assertions to the canActivate computed itself;
// bindPublished() legitimately keeps its own busy||intent||storage lock.
$canActivateBody = '';
$assert(preg_match('/const canActivate = computed\(\(\) => \{(.*?)\}\)/s', $home, $m) === 1,
    'canActivate computed located');
$canActivateBody = $m[1];
$assert(!str_contains($canActivateBody, 'intent.value || intentStorageError'),
    'canActivate no longer blanket-blocks on intent');
$assert(str_contains($canActivateBody, 'if (busy.value || intentStorageError.value || ambiguous.value || conflict.value) return false'),
    'busy/storage-error/ambiguous/conflict still block activate');

// ==== C: the resumed path is the same strict pipeline ====

$assert(str_contains($home, "'verify_unknown', 'publish_unknown', 'bind_unknown'"),
    'activate() still refuses unresolved *_unknown phases');
$assert(str_contains($home, '原操作结果尚未确认，请先点击「找回原开通状态」'),
    'unresolved phases still demand read-back recovery first');
$assert(str_contains($home, "current.phase === 'saved'"), 'resume enters verify step from saved');
$assert(str_contains($home, "current.phase === 'tested'"), 'resume enters publish step from tested');
$assert(str_contains($home, "current.phase === 'published'"), 'resume enters bind step from published');
$assert(str_contains($home, 'onboardingApi.verify('), 'resume reuses the verify endpoint');
$assert(str_contains($home, 'onboardingApi.publish('), 'resume reuses the publish endpoint');
$assert(str_contains($home, 'onboardingApi.bindDefaultChat('), 'resume reuses the bind endpoint');
$assert(!str_contains($home, 'request.post') && !str_contains($home, 'request.get'),
    'no raw http calls bypass the journaled onboarding api');

// ==== D: every「继续开通」notice points at a phase the button covers ====

$assert(str_contains($home, '已确认草稿尚未测试，请点击「继续开通」完成后续步骤。'),
    'saved-phase recovery notice promises the button');
$assert(str_contains($home, '原启用尚未完成，请点击「继续开通」完成启用与绑定。'),
    'tested-phase recovery notice promises the button');
$assert(str_contains($home, '尚未完成绑定，请点击「继续开通」完成最后一步。'),
    'published-phase recovery notice promises the button');
// saved notice sits in the branch where phase stays 'saved'; tested/published
// notices persist those same phases — all inside the continuable set.

// ==== E: save_unknown not-submitted proof unlocks; ambiguity keeps lock ====

$assert(str_contains($home, 'function saveNotSubmittedProof'), 'not-submitted proof helper exists');
$assert(str_contains($home, "problemCode(error) === 'SAND_AI_RESOURCE_NOT_FOUND'"),
    'save-key lookup miss is a server-side not-submitted proof');
$assert(str_contains($home, 'saved = await onboardingApi.readSavedDraftByKey(current.save_key)'),
    'save-key recovery still performs the read-back');
$assert(str_contains($home, '未找到原保存记录，原请求没有提交。请重新填写后开通。'),
    'not-submitted recovery unlocks with an explicit notice');
$assert(str_contains($home, 'resolveHomeActivationIntent(current)'),
    'not-submitted proof resolves the intent journal');

// ==== F: intended fail-closed locks are untouched ====

$assert(str_contains($home, 'ambiguous.value = true'), 'ambiguous flag still set on ambiguous verify');
$assert(str_contains($home, '本页不会重新发送测试'), 'ambiguous notice still forbids resend');
$assert(str_contains($home, '原测试尚无明确结果，请稍后再次找回'), 'pending verify still requires retry recovery');
$assert(str_contains($home, '原启用结果仍未确认，请稍后再次找回'), 'pending publish still requires retry recovery');
$assert(!str_contains($home, "includes('verify_unknown')"), 'continuable set never admits verify_unknown');
$assert(str_contains($home, 'v-if="foreignPending && !intent"'), 'foreign onboarding guide stays hidden under the lock');
$assert(str_contains($home, 'v-if="intent"'), 'recovery button still renders under the intent lock');

fwrite(STDOUT, 'ux_p2b_wizard_intent_deadlock_non_pg_test: ' . $checks . " checks passed\n");
