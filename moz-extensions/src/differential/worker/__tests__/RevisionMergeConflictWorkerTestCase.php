<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

final class RevisionMergeConflictWorkerTestCase extends PhabricatorTestCase {

  public function testDisabledGloballyChecksNothing() {
    $env = $this->configure(false, array());

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($this->newRepo()),
      pht('Nothing should be checked while the feature is off.'));
  }

  public function testDisabledGloballyOverridesTheRepositoryList() {
    $env = $this->configure(false, array('PHID-REPO-testrepo'));

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($this->newRepo()),
      pht(
        'The repository list should have no effect while the feature is off, '.
        'so the global switch is always a complete stop.'));
  }

  public function testEnabledWithAnEmptyListChecksEverything() {
    $env = $this->configure(true, array());

    $this->assertTrue(
      RevisionMergeConflictWorker::isEnabledForRepository($this->newRepo()),
      pht('An empty repository list should mean every repository.'));
  }

  public function testRepositoryMatchesByPHID() {
    $env = $this->configure(true, array('PHID-REPO-testrepo'));

    $this->assertTrue(
      RevisionMergeConflictWorker::isEnabledForRepository($this->newRepo()),
      pht('A PHID in the list should enable that repository.'));
  }

  public function testRepositoryIsNotMatchedByCallsign() {
    $env = $this->configure(true, array('TESTREPO'));

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($this->newRepo()),
      pht(
        'The list holds PHIDs, so a callsign should not enable a repository '.
        'and cannot be mistaken for one.'));
  }

  public function testRepositoryNotInTheListIsSkipped() {
    $env = $this->configure(true, array('PHID-REPO-someotherrepo'));

    $this->assertFalse(
      RevisionMergeConflictWorker::isEnabledForRepository($this->newRepo()),
      pht(
        'A repository absent from a non-empty list should not be checked, so '.
        'a staged rollout stays limited to the repositories named.'));
  }

  /**
   * Returns the scoped environment, which the caller has to hold in a local so
   * the override survives until the test method returns.
   */
  private function configure(
    bool $enabled,
    array $repository_phids): PhabricatorScopedEnv {
    $env = PhabricatorEnv::beginScopedEnv();

    $env->overrideEnvConfig(
      MergeConflictConfigOptions::OPTION_ENABLED,
      $enabled);
    $env->overrideEnvConfig(
      MergeConflictConfigOptions::OPTION_REPOSITORIES,
      $repository_phids);

    return $env;
  }

  private function newRepo(): PhabricatorRepository {
    return id(new PhabricatorRepository())
      ->setID(49)
      ->setPHID('PHID-REPO-testrepo')
      ->setCallsign('TESTREPO');
  }

}
