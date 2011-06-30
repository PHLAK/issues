<?php
class Default_Model_Mapper_Comment extends Issues_Model_Mapper_DbAbstract
{
    protected $_name = 'comment';

    public function getCommentById($id)
    {
        $db = $this->getReadAdapter();
        $sql = $db->select()
            ->from($this->getTableName())
            ->where('comment_id = ?', $id);
        $row = $db->fetchRow($sql);
        return ($row) ? new Default_Model_Comment($row) : false;
    }

    public function getCommentsByIssue($issue)
    {
        $db = $this->getReadAdapter();
        $sql = $db->select()
            ->from('comment');

        if ($issue instanceof Default_Model_Issue) {
            $sql->where('issue = ?', $issue->getIssueId());
        } else {
            $sql->where('issue = ?', (int) $issue);
        }

        $rows = $db->fetchAll($sql);
        if (!$rows) return array();

        $return = array();
        foreach ($rows as $i => $row) {
            $return[$i] = new Default_Model_Comment($row);
        }
        return $return;
    }

    public function insert(Default_Model_Comment $comment)
    {
        $data = array(
            'created_time'  => new Zend_Db_Expr('NOW()'),
            'created_by'    => $comment->getCreatedBy()->getUserId(),
            'issue'         => $comment->getIssue()->getIssueId(),
            'text'          => $comment->getText(),
        );

        $db = $this->getWriteAdapter();
        $db->insert($this->getTableName(), $data);
        return $db->lastInsertId();
    }
}