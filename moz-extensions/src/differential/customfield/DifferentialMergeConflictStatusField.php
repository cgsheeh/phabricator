<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Read-only field holding whether a revision's active diff merges cleanly into
 * its target branch. The value is computed by `RevisionMergeConflictWorker` and
 * written directly to storage (never via a transaction), then exposed in the
 * revision "Details" section and over Conduit so Lando can surface its own
 * warning.
 */
final class DifferentialMergeConflictStatusField
  extends DifferentialStoredCustomField {

  const STATUS_CLEAN = 'clean';
  const STATUS_CONFLICT = 'conflict';
  const STATUS_UNKNOWN = 'unknown';

  // Keys used in the stored JSON payload.
  const KEY_STATUS = 'status';
  const KEY_REASON = 'reason';
  const KEY_TARGET_COMMIT = 'checkedAgainstCommit';
  const KEY_DIFF_PHID = 'checkedAgainstDiffPHID';
  const KEY_DIFF_ID = 'checkedAgainstDiffID';
  const KEY_STACK_DIFF_PHIDS = 'checkedAgainstStackDiffPHIDs';
  const KEY_EPOCH = 'epoch';

/* -(  Core Properties and Field Identity  )--------------------------------- */

  public function getFieldKey() {
    return 'differential:merge-conflict-status';
  }

  public function getFieldKeyForConduit() {
    return 'merge.conflict.status';
  }

  public function getFieldName() {
    return pht('Merge Conflict Status');
  }

  public function getFieldDescription() {
    return pht(
      'Indicates whether the active diff merges cleanly into the target '.
      'branch.');
  }

  public function isFieldEnabled() {
    // Turning the feature off hides the field everywhere rather than leaving a
    // status behind that nothing will update.
    return (bool)PhabricatorEnv::getEnvConfig(
      MergeConflictConfigOptions::OPTION_ENABLED);
  }

  public function canDisableField() {
    // The field is managed automatically, so don't allow it to be switched off.
    return false;
  }

/* -(  Read-only: not user-editable  )--------------------------------------- */

  public function isFieldEditable() {
    return false;
  }

  public function shouldAppearInApplicationTransactions() {
    return false;
  }

  public function shouldAppearInEditView() {
    return false;
  }

  public function renderEditControl(array $handles) {
    return null;
  }

  public function newCommentAction() {
    return null;
  }

/* -(  Storage  )------------------------------------------------------------ */

  public function getValueForStorage() {
    return phutil_json_encode($this->getValue());
  }

  public function setValueFromStorage($value) {
    try {
      $this->setValue(phutil_json_decode($value));
    } catch (PhutilJSONParserException $ex) {
      $this->setValue(array());
    }
    return $this;
  }

  /**
   * Persist a computed status for a revision directly to field storage,
   * bypassing the transaction editor so routine rechecks don't generate feed
   * stories, mail, or Herald evaluation (which would also risk re-triggering
   * the recompute). Mirrors the upsert in
   * `PhabricatorCustomField::applyApplicationTransactionExternalEffects()`.
   */
  public function writeStatusForObject(
    string $object_phid,
    array $value): self {
    $table = $this->newStorageObject();
    $conn_w = $table->establishConnection('w');

    queryfx(
      $conn_w,
      'INSERT INTO %T (objectPHID, fieldIndex, fieldValue)
        VALUES (%s, %s, %s)
        ON DUPLICATE KEY UPDATE fieldValue = VALUES(fieldValue)',
      $table->getTableName(),
      $object_phid,
      $this->getFieldIndex(),
      phutil_json_encode($value));

    return $this;
  }

  /**
   * Reads the currently-stored status payload for a revision directly from
   * field storage, returning the decoded array or `null` if nothing is stored
   * (or the stored value can't be decoded). Used to decide whether a recompute
   * would be redundant.
   */
  public function readStoredValueForObject(string $object_phid): ?array {
    $table = $this->newStorageObject();

    $row = queryfx_one(
      $table->establishConnection('r'),
      'SELECT fieldValue FROM %T WHERE objectPHID = %s AND fieldIndex = %s',
      $table->getTableName(),
      $object_phid,
      $this->getFieldIndex());

    if (!$row) {
      return null;
    }

    try {
      $value = phutil_json_decode($row['fieldValue']);
    } catch (PhutilJSONParserException $ex) {
      return null;
    }

    // A value that decodes to something other than an array is as unusable as
    // one that does not decode at all.
    if (!is_array($value)) {
      return null;
    }

    return $value;
  }

/* -(  Property View  )------------------------------------------------------ */

  public function shouldAppearInPropertyView() {
    return true;
  }

  public function renderPropertyViewValue(array $handles) {
    $value = $this->getValue();
    if (!is_array($value) || empty($value[self::KEY_STATUS])) {
      return null;
    }

    if (!$this->isEnabledForRevisionRepository()) {
      return null;
    }

    // A revision that will never land has no useful mergeability. Closing one
    // also attaches a new commit-derived diff, which would otherwise leave the
    // stored status looking permanently stale.
    if ($this->isRevisionClosed()) {
      return null;
    }

    $item = new PHUIStatusItemView();

    if ($this->isStatusStale($value)) {
      $item
        ->setIcon(
          PHUIStatusItemView::ICON_CLOCK,
          'blue',
          pht('Recomputing'))
        ->setTarget(pht('Recomputing for the latest diff'));

      return id(new PHUIStatusListView())->addItem($item);
    }

    switch ($value[self::KEY_STATUS]) {
      case self::STATUS_CLEAN:
        $item
          ->setIcon(
            PHUIStatusItemView::ICON_ACCEPT,
            'green',
            pht('Merges Cleanly'))
          ->setTarget(pht('Merges cleanly into the target branch'));
        break;
      case self::STATUS_CONFLICT:
        $item
          ->setIcon(
            PHUIStatusItemView::ICON_REJECT,
            'red',
            pht('Merge Conflict'))
          ->setTarget(pht('Does not merge cleanly into the target branch'));
        break;
      case self::STATUS_UNKNOWN:
      default:
        // The reason is usually actionable -- a stack whose parent has not
        // landed, or a patch that no longer applies -- so show it alongside.
        $item
          ->setIcon(
            PHUIStatusItemView::ICON_QUESTION,
            'grey',
            pht('Unknown'))
          ->setTarget(pht('Mergeability could not be determined'))
          ->setNote(idx($value, self::KEY_REASON));
        break;
    }

    return id(new PHUIStatusListView())->addItem($item);
  }

/* -(  Conduit  )------------------------------------------------------------ */

  public function shouldAppearInConduitDictionary() {
    return true;
  }

  public function getConduitDictionaryValue() {
    if (!$this->isEnabledForRevisionRepository()) {
      return null;
    }

    $value = $this->getValue();
    if (!is_array($value)) {
      return null;
    }
    return $value;
  }

/* -(  Helpers  )------------------------------------------------------------ */

  /**
   * Whether merge conflict detection is turned on for this revision's
   * repository, so a per-repository rollout does not leave statuses showing on
   * repositories that are no longer being checked.
   */
  private function isEnabledForRevisionRepository(): bool {
    $object = $this->getObject();
    if (!($object instanceof DifferentialRevision)) {
      return false;
    }

    $repository = $object->getRepository();
    if (!$repository) {
      return false;
    }

    return RevisionMergeConflictWorker::isEnabledForRepository($repository);
  }

  /**
   * Whether the revision has reached a state it will never land from, in which
   * case there is nothing useful to display.
   */
  private function isRevisionClosed(): bool {
    $object = $this->getObject();
    if (!($object instanceof DifferentialRevision)) {
      return false;
    }

    // `isClosed` covers abandoned revisions too.
    return $object->isClosed();
  }

  /**
   * A stored status is stale if it was computed against a diff other than the
   * revision's current active diff. We suppress display in that case because a
   * fresh check is already queued.
   */
  private function isStatusStale(array $value): bool {
    $checked_phid = idx($value, self::KEY_DIFF_PHID);
    if (!$checked_phid) {
      return false;
    }

    $object = $this->getObject();
    if (!($object instanceof DifferentialRevision)) {
      return false;
    }

    $active_diff = $object->getActiveDiff();
    if (!$active_diff) {
      return false;
    }

    return ($active_diff->getPHID() !== $checked_phid);
  }

}
