<?php
class Default_Model_Mapper_Issue extends Issues_Model_Mapper_DbAbstract
{
    protected $_name = 'issue';
    protected $_modelClass = 'Default_Model_Issue';

    public function getIssueById($issueId)
    {
        $db = $this->getReadAdapter();
        $sql = $db->select()
            ->from($this->getTableName())
            ->where('issue_id = ?', $issueId);

        $sql = $this->_addAclJoins($sql);
        $sql = $this->_addRelationJoins($sql, 'issue');

        $row = $db->fetchRow($sql);
        return ($row) ? $this->_rowToModel($row) : false;
    }

    public function filterIssues($status = false)
    {
        $db = $this->getReadAdapter();
        $sql = $db->select()
            ->from($this->getTableName());
        if ($status) {
            $sql->where('status = ?', $status);
        }

        $sql = $this->_addLabelConcat($sql);
        $sql = $this->_addAclJoins($sql);
        $sql = $this->_addRelationJoins($sql, 'issue');
        $rows = $db->fetchAll($sql);
        return $this->_rowsToModels($rows);
    }

    public function getIssuesByProject($project)
    {
        if ($project instanceof Issues_Model_Project) {
            $project = $project->getProjectId();
        } else {
            if (!is_numeric($project)) {
                return false;
            }
        }

        $db = $this->getReadAdapter();
        $sql = $db->select()
            ->from($this->getTableName())
            ->where('project = ?', $project);

        $sql = $this->_addAclJoins($sql);
        $sql = $this->_addLabelConcat($sql);
        $sql = $this->_addRelationJoins($sql, 'issue');

        $rows = $db->fetchAll($sql);
        return $this->_rowsToModels($rows);
    }

    public function getAllIssues()
    {
        $db = $this->getReadAdapter();
        $sql = $db->select()
            ->from($this->getTableName());
        $sql = $this->_addAclJoins($sql);

        $rows = $db->fetchAll($sql);
        return $this->_rowsToModels($rows);
    }

    public function insert(Default_Model_Issue $issue)
    {
        $data = array(
            'title'             => $issue->getTitle(),
            'description'       => $issue->getDescription(),
            'status'            => $issue->getStatus(),
            'project'           => $issue->getProject()->getProjectId(),
            'created_by'        => $issue->getCreatedBy()->getUserId(),
            'created_time'      => new Zend_Db_Expr('NOW()'),
            'private'           => $issue->isPrivate() ? 1 : 0,
        );

        $db = $this->getWriteAdapter();
        $db->insert($this->getTableName(), $data);
        return $db->lastInsertId();
    }

    public function updateLastUpdate($issue)
    {
        $data = array(
            'last_update_time' => new Zend_Db_Expr('NOW()')
        );
        $db = $this->getWriteAdapter();
        return $db->update($this->getTableName(), $data, $db->quoteInto('issue_id = ?', $issue->getIssueId()));
    }

    public function addLabelToIssue(Default_Model_Issue $issue, Default_Model_Label $label)
    {
        $data = array(
            'issue_id'  => $issue->getIssueId(),
            'label_id'  => $label->getLabelId()
        );

        $db = $this->getWriteAdapter();
        try {
            $db->insert('issue_label_linker', $data);
        } catch (Exception $e) {} // probably a duplicate key
            return true;
    }

    public function removeLabelFromIssue(Default_Model_Issue $issue, Default_Model_Label $label)
    {
        $where = array(
            'issue_id = ?'  => $issue->getIssueId(),
            'label_id = ?'  => $label->getLabelId()
        );

        $db = $this->getWriteAdapter();
        $db->delete('issue_label_linker', $where);
    }

    public function countIssuesByLabel(Default_Model_Label $label)
    {
        $db = $this->getReadAdapter();
        $sql = $db->select()
            ->from(array('ill'=>'issue_label_linker'), array('count' => 'COUNT(*)'))
            ->join(array('i'=>'issue'), 'ill.issue_id = i.issue_id')
            ->where('ill.label_id = ?', $label->getLabelId());

        $sql = $this->_addAclJoins($sql, 'i', 'issue_id');

        return $db->fetchOne($sql);
    }

    public function getIssueCounts()
    {
        $db = $this->getReadAdapter();

        $all = $db->select()
            ->from('issue', array(new Zend_Db_Expr("'all'"), 'COUNT(*)'));

        $userId = Zend_Registry::get('Default_DiContainer')
            ->getUserService()->getIdentity()->getUserId() ?: 0;

        $mine = $db->select()
            ->from('issue', array(new Zend_Db_Expr("'mine'"), 'COUNT(*)'))
            ->where('assigned_to = ?', $userId);

        $unassigned = $db->select()
            ->from('issue', array(new Zend_Db_Expr("'unassigned'"), 'COUNT(*)'))
            ->where('isnull(assigned_to)');

        $result = $db->fetchAll($db->select()->union(array(
            $all, $mine, $unassigned
        )));

        $return = array(
            'all'           => $result[0]['COUNT(*)'],
            'mine'          => $result[1]['COUNT(*)'],
            'unassigned'    => $result[2]['COUNT(*)']
        );

        return $return;
    }

    public function getIssuesByMilestone($milestone, $status = null)
    {
        if ($milestone instanceof Default_Model_Milestone) {
            $milestone = $milestone->getMilestoneId();
        }

        $db = $this->getReadAdapter();
        $sql = $db->select()
            ->from(array('iml'=>'issue_milestone_linker'))
            ->join(array('i'=>'issue'), 'i.issue_id = iml.issue_id')
            ->where('iml.milestone_id = ?', $milestone);

        if ($status) {
            $sql->where('i.status = ?', $status);
        }

        $sql = $this->_addAclJoins($sql, 'i', 'issue_id');

        $rows = $db->fetchAll($sql);
        return $this->_rowsToModels($rows);
    }

    protected function _addAclJoins(Zend_Db_Select $sql, $alias = null, $primaryKey = null)
    {
        $sql = parent::_addAclJoins($sql, $alias, $primaryKey);

        if ($alias === null) {
            $alias = $this->getTableName();
        }

        $table = $this->getTableName();

        if ($primaryKey === null) {
            $primaryKey = $table . '_id';
        }

        $roles = Zend_Registry::get('Default_DiContainer')
            ->getUserService()
            ->getIdentity()
            ->getRoles();

        $sql->join(array('p'=>'project'), "p.project_id = $alias.project", array())
            ->joinLeft(
                array('p_arr' => 'acl_resource_record'),
                "`p_arr`.`resource_type` = 'project' AND `p_arr`.`resource_id` = `{$alias}`.`project`",
                array())
                ->where('((p.private = ?', 1)
                ->where('p_arr.role_id IN (?))', $roles)
                ->orWhere('p.private = ?)', 0);

        return $sql;
    }

    protected function _addLabelConcat(Zend_Db_Select $sql, $alias = null)
    {
        $alias = $alias ?: $this->getTableName();

        // have to have array() as the last param or issue_id will get 
        // overwritten with a 0 if there are no issues to join
        $sql->joinLeft(array('ill'=>'issue_label_linker'), "{$alias}.issue_id = ill.issue_id", array()); 
        $sql->columns(array('labels'=>'GROUP_CONCAT(DISTINCT ill.label_id SEPARATOR \' \')'));
        $sql->group($alias.'.issue_id');
        return $sql;
    }

    protected function _addRelationJoins(Zend_Db_Select $sql, $alias = null)
    {
        $alias = $alias ?: 'i';

        $sql->join(array('r_project'=>'project'), "r_project.project_id = $alias.project", array(
            'project.project_id'    => 'project_id',
            'project.name'          => 'name',
            'project.private'       => 'private'
        ));

        $sql->join(array('r_createdby'=>'user'), "r_createdby.user_id = $alias.created_by", array(
            'created_by.user_id'        => 'user_id',
            'created_by.username'       => 'username',
            'created_by.password'       => 'password',
            'created_by.last_login'     => 'last_login',
            'created_by.last_ip'        => new Zend_Db_Expr('INET_NTOA(`r_createdby`.`last_ip`)'),
            'created_by.register_time'  => 'register_time',
            'created_by.register_ip'    => new Zend_Db_Expr('INET_NTOA(`r_createdby`.`register_ip`)')
        ));

        $sql->joinLeft(array('r_assignedto'=>'user'), "r_assignedto.user_id = $alias.assigned_to", array(
            'assigned_to.user_id'        => 'user_id',
            'assigned_to.username'       => 'username',
            'assigned_to.password'       => 'password',
            'assigned_to.last_login'     => 'last_login',
            'assigned_to.last_ip'        => new Zend_Db_Expr('INET_NTOA(`r_assignedto`.`last_ip`)'),
            'assigned_to.register_time'  => 'register_time',
            'assigned_to.register_ip'    => new Zend_Db_Expr('INET_NTOA(`r_assignedto`.`register_ip`)')
        ));

        return $sql;
    }

    protected function _rowToModel($row, $class = false)
    {
        if (array_key_exists('project.project_id', $row)) {
            $row['project'] = new Default_Model_Project(array(
                'project_id'    => $row['project.project_id'],
                'name'          => $row['project.name'],
                'private'       => $row['project.private']
            ));

            unset($row['project.project_id'],
                $row['project.name'],
                $row['project.private']);
        }

        if (array_key_exists('created_by.user_id', $row)) {
            $row['created_by'] = new Default_Model_User(array(
                'user_id'       => $row['created_by.user_id'],
                'username'      => $row['created_by.username'],
                'password'      => $row['created_by.password'],
                'last_login'    => $row['created_by.last_login'],
                'last_ip'       => $row['created_by.last_ip'],
                'register_time' => $row['created_by.register_time'],
                'register_ip'   => $row['created_by.register_ip'],
            ));

            unset($row['created_by.user_id'],
                $row['created_by.username'],
                $row['created_by.password'],
                $row['created_by.last_login'],
                $row['created_by.last_ip'],
                $row['created_by.register_time'],
                $row['created_by.register_ip']
            );
        }

        if (array_key_exists('assigned_to.user_id', $row) && $row['assigned_to.user_id'] != null) {
            $row['created_by'] = new Default_Model_User(array(
                'user_id'       => $row['assigned_to.user_id'],
                'username'      => $row['assigned_to.username'],
                'password'      => $row['assigned_to.password'],
                'last_login'    => $row['assigned_to.last_login'],
                'last_ip'       => $row['assigned_to.last_ip'],
                'register_time' => $row['assigned_to.register_time'],
                'register_ip'   => $row['assigned_to.register_ip'],
            ));
        }

        unset($row['assigned_to.user_id'],
            $row['assigned_to.username'],
            $row['assigned_to.password'],
            $row['assigned_to.last_login'],
            $row['assigned_to.last_ip'],
            $row['assigned_to.register_time'],
            $row['assigned_to.register_ip']
        );
        return parent::_rowToModel($row);
    }
}
