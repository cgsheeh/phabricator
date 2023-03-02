<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
* Extends Differential with a 'Uplift Request' field.
*/
final class DifferentialUpliftRequestCustomField
    extends DifferentialStoredCustomField {

    private UpliftRequestForm $old_form;
    private UpliftRequestForm $new_form;
    private $proxy;

    /* -(  Core Properties and Field Identity  )--------------------------------- */

    public function readValueFromRequest(AphrontRequest $request) {
        // there is a $request->getRequestData() that we could inspect to get a
        // better idea of what's available.
        // I imagine this should be setting `$new_form`
        $uplift_data = $request->getJSONMap($this->getFieldKey());
        $this->new_form = new UpliftRequestForm($uplift_data);
    }

    public function getFieldKey() {
        return 'differential:uplift-request';
    }

    public function getFieldKeyForConduit() {
        return 'uplift.request';
    }

    public function getFieldValue() {
        return $this->getValue();
    }

    public function getFieldName() {
        return pht('Uplift Request form');
    }

    public function getFieldDescription() {
        // Rendered in 'Config > Differential > differential.fields'
        return pht('Renders uplift request form.');
    }

    public function isFieldEnabled() {
        return true;
    }

    public function canDisableField() {
        // Field can't be switched off in configuration
        return false;
    }

    /* -(  ApplicationTransactions  )-------------------------------------------- */

    public function shouldAppearInApplicationTransactions() {
        // Required to be editable
        return true;
    }

    /* -(  Edit View  )---------------------------------------------------------- */

    public function shouldAppearInEditView() {
        // Should the field appear in Edit Revision feature, and the
        // action menu.
        return true;
    }

    // How the uplift text is rendered in the "Details" section.
    public function renderPropertyViewValue(array $handles) {
        // TODO check old_form is empty.
        if (empty($this->getValue())) {
            return null;
        }

        return new PHUIRemarkupView($this->getViewer(), $this->old_form->getRemarkup());
    }

    // Returns `true` if the field meets all conditions to be editable.
    public function isFieldActive() {
        return $this->isUpliftTagSet() && $this->objectHasBugNumber();
    }

    public function objectHasBugNumber(): bool {
        // Similar idea to `BugStore::resolveBug`.
        $bugzillaField = new DifferentialBugzillaBugIDField();
        $bugzillaField->setObject($this->getObject());
        (new PhabricatorCustomFieldStorageQuery())
            ->addField($bugzillaField)
            ->execute();
        $bug = $bugzillaField->getValue();

        if (!$bug) {
            return false;
        }

        return true;
    }

    // How the field can be edited in the "Edit Revision" menu.
    public function renderEditControl(array $handles) {
        // TODO this make the field not display, but it clears when saving
        // from here.
        return null;
    }

    // -- Comment action things

    public function getCommentActionLabel() {
        return pht('Request Uplift');
    }

    // Return `true` if the `uplift` tag is set on the repository belonging to
    // this revision.
    private function isUpliftTagSet() {
        $revision = $this->getObject();
        $viewer = $this->getViewer();

        if ($revision == null || $viewer == null) {
            return false;
        }

        try {
            $repository_projects = PhabricatorEdgeQuery::loadDestinationPHIDs(
                $revision->getFieldValuesForConduit()['repositoryPHID'],
                PhabricatorProjectObjectHasProjectEdgeType::EDGECONST
            );
        } catch (Exception $e) {
            return false;
        }

        if (!(bool)$repository_projects) {
            return false;
        }

        $uplift_project = id(new PhabricatorProjectQuery())
            ->setViewer($viewer)
            ->withNames(array('uplift'))
            ->executeOne();

        // The `uplift` project isn't created or can't be found.
        if (!(bool)$uplift_project) {
            return false;
        }

        // If the `uplift` project PHID is in the set of all project PHIDs
        // attached to the repo, return `true`.
        if (in_array($uplift_project->getPHID(), $repository_projects)) {
            return true;
        }

        return false;
    }

    public function newCommentAction() {
        // Returning `null` causes no comment action to render, effectively
        // "disabling" the field.
        if (!$this->isFieldActive()) {
            return null;
        }

        $action = id(new PhabricatorUpdateUpliftCommentAction())
            ->setConflictKey('revision.action')
            // TODO make this use old_form
            ->setValue($this->getValue())
            ->setInitialValue(self::BETA_UPLIFT_FIELDS)
            ->setSubmitButtonText(pht('Request Uplift'));

        return $action;
    }

    public function validateApplicationTransactions(
        PhabricatorApplicationTransactionEditor $editor,
        $type, array $xactions) {

        $errors = parent::validateApplicationTransactions($editor, $type, $xactions);

        foreach($xactions as $xaction) {
            $this->new_form = new UpliftRequestForm(
                phutil_json_decode($xaction->getNewValue())
            );

            // Validate that the form is correctly filled out.
            // This should always be a string (think if the value came from the remarkup edit)
            $validation_errors = $this->new_form->validateUpliftForm();

            // Push errors into the revision save stack
            foreach($validation_errors as $validation_error) {
                $errors[] = new PhabricatorApplicationTransactionValidationError(
                    $type,
                    '',
                    pht($validation_error)
                );
            }
        }

        return $errors;
    }

    // When storing the value convert the question => answer mapping to a JSON string.
    public function getValueForStorage(): string {
        // TODO
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

    public function setValueFromApplicationTransactions($value) {
        $this->new_form = new UpliftRequestForm($value);
        return $this;
    }

    public function setValue($value) {
        if (is_array($value)) {
            parent::setValue($value);
            return;
        }

        try {
            parent::setValue(phutil_json_decode($value));
        } catch (Exception $e) {
            parent::setValue(array());
        }
    }


    /* -(  Property View  )------------------------------------------------------ */

    public function shouldAppearInPropertyView() {
        return true;
    }

    /* -(  Global Search  )------------------------------------------------------ */

    public function shouldAppearInGlobalSearch() {
        return true;
    }

    /* -(  Conduit  )------------------------------------------------------------ */

    public function shouldAppearInConduitDictionary() {
        // Should the field appear in `differential.revision.search`
        return true;
    }

    public function shouldAppearInConduitTransactions() {
        // Required if needs to be saved via Conduit (i.e. from `arc diff`)
        return true;
    }

    protected function newConduitSearchParameterType() {
        return new ConduitStringParameterType();
    }

    protected function newConduitEditParameterType() {
        // Define the type of the parameter for Conduit
        return new ConduitStringParameterType();
    }

    public function readFieldValueFromConduit(string $value) {
        return $value;
    }

    public function isFieldEditable() {
        // Has to be editable to be written from `arc diff`
        return true;
    }

    public function shouldDisableByDefault() {
        return false;
    }

    public function shouldOverwriteWhenCommitMessageIsEdited() {
        return false;
    }

    public function getApplicationTransactionTitle(
        PhabricatorApplicationTransaction $xaction) {

        if($this->proxy) {
            return $this->proxy->getApplicationTransactionTitle($xaction);
        }

        $author_phid = $xaction->getAuthorPHID();

        return pht('%s updated the uplift request field.', $xaction->renderHandleLink($author_phid));
    }

    // NOTE: `phab-bot` reads Phabricator feed entries to determine when to
    // update Phabricator revisions for uplifts. This means this function is
    // critical for the uplift workflow.
    public function getApplicationTransactionTitleForFeed(
        PhabricatorApplicationTransaction $xaction) {

        if($this->proxy) {
            return $this->proxy->getApplicationTransactionTitle($xaction);
        }

        $author_phid = $xaction->getAuthorPHID();
        $object_phid = $xaction->getObjectPHID();

        return pht(
            '%s updated the uplift request field for %s.',
            $xaction->renderHandleLink($author_phid),
            $xaction->renderHandleLink($object_phid)
        );
    }
}

