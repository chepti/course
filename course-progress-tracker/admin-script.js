jQuery(document).ready(function($) {
    const modal = $('#cpt-details-modal');
    const modalBody = $('#cpt-modal-body');
    const modalTitle = $('#cpt-modal-title');
    const modalBackdrop = $('#cpt-modal-backdrop');
    const closeModal = $('#cpt-modal-close');

    $('.progress-cell').on('click', function() {
        const userId = $(this).data('userId');
        const postId = $(this).data('postId');
        const userName = $(this).closest('tr').find('td:first').text();
        const unitName = $('.progress-table th').eq($(this).index()).text();

        modalTitle.text(`פירוט התקדמות: ${userName} - ${unitName}`);
        modalBody.html('<p>טוען נתונים...</p>');
        modal.show();

        $.ajax({
            url: cpt_admin_data.ajax_url,
            type: 'GET',
            data: {
                action: 'cpt_get_user_unit_details',
                nonce: cpt_admin_data.nonce,
                user_id: userId,
                post_id: postId
            },
            success: function(response) {
                if (response.success) {
                    const allSections = response.data.all_sections;
                    const completedSections = response.data.completed_sections;
                    const sectionActivities = response.data.section_activities || {};
                    
                    let html = '<div class="cpt-details-content">';
                    
                    if (allSections.length === 0) {
                        html += '<p>אין נתונים להצגה עבור יחידה זו.</p>';
                    } else {
                        allSections.forEach(section => {
                            const isCompleted = completedSections.hasOwnProperty(section);
                            const sectionData = sectionActivities[section] || {};
                            const progress = sectionData.progress || 0;
                            const activities = sectionData.activities || [];
                            
                            // Determine icon and status
                            let icon = '❌';
                            let statusClass = 'status-not-started';
                            if (progress >= 100) {
                                icon = '✔️';
                                statusClass = 'status-completed';
                            } else if (progress > 0) {
                                icon = '◐';
                                statusClass = 'status-in-progress';
                            }
                            
                            html += `<div class="cpt-section-detail ${statusClass}">`;
                            html += `<div class="cpt-section-header">`;
                            html += `<span class="cpt-section-icon">${icon}</span>`;
                            html += `<span class="cpt-section-name">${section}</span>`;
                            html += `<span class="cpt-section-progress">${Math.round(progress)}%</span>`;
                            html += `</div>`;
                            
                            // Show activities if any
                            if (activities.length > 0) {
                                html += `<div class="cpt-activities-list">`;
                                html += `<strong>פעילויות:</strong>`;
                                html += `<ul>`;
                                activities.forEach(activity => {
                                    const typeLabel = getActivityTypeLabel(activity.activity_type);
                                    const data = JSON.parse(activity.activity_data || '{}');
                                    let activityText = typeLabel;
                                    
                                    if (activity.activity_type === 'video_watch' && data.video_id) {
                                        activityText += ` (וידאו: ${data.video_id.substring(0, 8)}...)`;
                                    } else if (activity.activity_type === 'button_click' && data.text) {
                                        activityText += ` (${data.text.substring(0, 30)})`;
                                    } else if (activity.activity_type === 'scroll' && data.time_spent) {
                                        activityText += ` (${Math.round(data.time_spent)} שניות)`;
                                    }
                                    
                                    html += `<li>${activityText} - ${formatDate(activity.created_at)}</li>`;
                                });
                                html += `</ul>`;
                                html += `</div>`;
                            }
                            
                            html += `</div>`;
                        });
                    }
                    
                    html += '</div>';
                    modalBody.html(html);
                } else {
                    modalBody.html('<p>שגיאה בטעינת הנתונים.</p>');
                }
            },
            error: function() {
                modalBody.html('<p>שגיאת תקשורת עם השרת.</p>');
            }
        });
    });

    function hideModal() {
        modal.hide();
    }

    function getActivityTypeLabel(type) {
        const labels = {
            'video_watch': 'צפייה בסרטון',
            'button_click': 'קליק על כפתור',
            'scroll': 'גלילה ושהייה',
            'comment': 'תגובה בדיון',
            'manual_check': 'סימון ידני'
        };
        return labels[type] || type;
    }

    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('he-IL', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    closeModal.on('click', hideModal);
    modalBackdrop.on('click', hideModal);
});
