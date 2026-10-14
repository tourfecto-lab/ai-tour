<?php

/** Tourfecto - CRM Pipeline Stage Model @version 1.1.0 */
class CrmPipelineStage extends Model
{
    protected $table = 'crm_pipeline_stages';
    protected $fillable = [
        'agency_id', 'pipeline_id', 'name', 'slug', 'description',
        'sort_order', 'win_probability', 'is_won', 'is_lost', 'color',
    ];

    /**
     * مراحل مسار معيّن؛ لو $pipelineId=null يرجع المراحل العامة الافتراضية
     * (نفس سلوك CrmController::listPipelineStages الحالي).
     */
    public function forPipeline(?int $pipelineId): array
    {
        if ($pipelineId === null) {
            return $this->db->query(
                "SELECT * FROM `crm_pipeline_stages` WHERE `agency_id` IS NULL AND `pipeline_id` IS NULL ORDER BY `sort_order` ASC"
            );
        }
        return $this->db->query(
            "SELECT * FROM `crm_pipeline_stages` WHERE `pipeline_id` = ? ORDER BY `sort_order` ASC",
            [$pipelineId]
        );
    }
}
