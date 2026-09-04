<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class DifferentialMergeConflictStatusFieldTestCase
  extends PhabricatorTestCase {

  public function testStatusValueRecordsTheCheckedDiff() {
    $value = $this->newStatusValue();

    $this->assertEqual(
      'PHID-DIFF-active',
      idx(
        $value,
        DifferentialMergeConflictStatusField::KEY_DIFF_PHID),
      pht('The payload should record the diff the check ran against.'));

    $this->assertEqual(
      456,
      idx($value, DifferentialMergeConflictStatusField::KEY_DIFF_ID),
      pht(
        'The diff ID should be recorded as an integer, so the payload '.
        'carries a JSON number rather than the string Lisk hands back.'));
  }

  public function testStatusValueRecordsWhenTheCheckRan() {
    $value = $this->newStatusValue();

    $this->assertEqual(
      1757000000,
      idx($value, DifferentialMergeConflictStatusField::KEY_EPOCH),
      pht('The payload should record when the check ran.'));
  }

  public function testStatusValueCarriesTheEngineResult() {
    $value = $this->newStatusValue();

    $this->assertEqual(
      DifferentialMergeConflictStatusField::STATUS_CLEAN,
      idx($value, DifferentialMergeConflictStatusField::KEY_STATUS),
      pht('The payload should carry the status the engine reported.'));

    $this->assertEqual(
      'Merged against the current target branch tip.',
      idx($value, DifferentialMergeConflictStatusField::KEY_REASON),
      pht('The payload should carry the reason the engine reported.'));

    $this->assertEqual(
      'ffffffffffffffffffffffffffffffffffffffff',
      idx($value, DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT),
      pht('The payload should record the target branch tip that was merged.'));

    $this->assertEqual(
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      idx($value, DifferentialMergeConflictStatusField::KEY_BASE_COMMIT),
      pht(
        'The payload should record the base the stack was merged from, so a '.
        'reader can tell how old the answer is without re-deriving it.'));
  }

  public function testStatusValueRecordsTheStackItDependsOn() {
    $value = $this->newStatusValue();

    $this->assertEqual(
      array('PHID-DIFF-parent', 'PHID-DIFF-active'),
      idx(
        $value,
        DifferentialMergeConflictStatusField::KEY_STACK_DIFF_PHIDS),
      pht(
        'The payload should record every diff the check depends on, so a '.
        'change anywhere in the stack invalidates the stored result.'));
  }

  public function testStatusValueTolerantOfAnUnresolvedStack() {
    // The stack may not resolve at all, which is itself a reason for an
    // `unknown` result, so the worker has nothing to record.
    $value = DifferentialMergeConflictStatusField::newStatusValue(
      array(
        'status' => DifferentialMergeConflictStatusField::STATUS_UNKNOWN,
        'reason' => 'The patch for this revision does not apply.',
      ),
      $this->newDiff(),
      null,
      1757000000);

    $this->assertEqual(
      null,
      idx(
        $value,
        DifferentialMergeConflictStatusField::KEY_STACK_DIFF_PHIDS),
      pht('An unresolved stack should be recorded as `null`.'));

    $this->assertEqual(
      null,
      idx($value, DifferentialMergeConflictStatusField::KEY_TARGET_COMMIT),
      pht(
        'An `unknown` result records no target commit, so a retry is never '.
        'short-circuited.'));

    $this->assertEqual(
      null,
      idx($value, DifferentialMergeConflictStatusField::KEY_BASE_COMMIT),
      pht('An `unknown` result may not have resolved a base commit.'));
  }

  private function newStatusValue(): array {
    return DifferentialMergeConflictStatusField::newStatusValue(
      array(
        'status' => DifferentialMergeConflictStatusField::STATUS_CLEAN,
        'reason' => 'Merged against the current target branch tip.',
        'baseCommit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'targetCommit' => 'ffffffffffffffffffffffffffffffffffffffff',
      ),
      $this->newDiff(),
      array('PHID-DIFF-parent', 'PHID-DIFF-active'),
      1757000000);
  }

  private function newDiff(): DifferentialDiff {
    return id(new DifferentialDiff())
      ->setID(456)
      ->setPHID('PHID-DIFF-active');
  }

}
