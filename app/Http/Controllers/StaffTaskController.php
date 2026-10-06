<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\TaskController;

/**
 * Staff-portal task surface. Inherits EVERYTHING from the admin
 * TaskController (same validation, plan limits, WhatsApp-on-assign pings,
 * activity entries attributed to the signed-in member) because the
 * staff.portal middleware populates the same ShopContext.
 *
 * The RESTRICTED surface is defined purely by which routes exist:
 * no DELETE /tasks/{id} and no /tasks/{id}/remind are registered for staff.
 */
class StaffTaskController extends TaskController
{
    // store / update / move / complete / activity — all inherited as-is.
}
