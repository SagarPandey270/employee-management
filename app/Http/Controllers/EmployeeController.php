<?php
namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum'); // Ensures only authenticated users can access
    }

    public function uploadCSV(Request $request)
    {
        // 1. Validate uploaded file
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        // 2. Store file temporarily
        $file = $request->file('file');
        $filename = 'employees_' . time() . '.csv';
        $path = $file->storeAs('temp', $filename);
        
        // 3. Read the CSV file using Laravel Excel
        try {
            $data = Excel::toArray([], storage_path("app/{$path}"))[0];
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to read CSV file: ' . $e->getMessage()], 500);
        }

        if (empty($data) || count($data) < 2) {
            return response()->json(['error' => 'CSV file is empty or missing data.'], 400);
        }

        // 4. Validate headers
        $expectedHeaders = ['EmployeeName', 'Email', 'Number', 'Designation', 'Address'];
        $header = array_map('trim', $data[0]);

        if ($header !== $expectedHeaders) {
            return response()->json(['error' => 'Invalid CSV header. Expected: ' . implode(', ', $expectedHeaders)], 400);
        }

        // 5. Initialize counters
        $totalRecords = count($data) - 1; // Exclude header
        $insertedRecords = 0;
        $skippedRecords = 0;
        $errors = [];

        // 6. Process each row
        foreach ($data as $index => $row) {
            if ($index === 0) continue; // Skip header

            $rowData = [
                'name' => trim($row[0] ?? ''),
                'email' => trim($row[1] ?? ''),
                'number' => trim($row[2] ?? ''),
                'designation' => trim($row[3] ?? ''),
                'address' => trim($row[4] ?? ''),
            ];

            $validator = Validator::make($rowData, [
                'name' => 'required|string',
                'email' => 'required|email|unique:employees,email',
                'number' => 'required|digits:10|unique:employees,number',
                'designation' => 'required|string',
                'address' => 'required|string',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$index}: " . implode(', ', $validator->errors()->all());
                $skippedRecords++;
                continue;
            }

            try {
                Employee::create($rowData);
                $insertedRecords++;
            } catch (\Exception $e) {
                $errors[] = "Row {$index}: DB insert error - " . $e->getMessage();
                $skippedRecords++;
            }
        }

        // 7. Return response
        return response()->json([
            'message' => 'CSV file processed successfully.',
            'totalRecords' => $totalRecords,
            'insertedRecords' => $insertedRecords,
            'skippedRecords' => $skippedRecords,
            'errors' => $errors
        ]);
    }

    public function getEmployees(Request $request)
    {
        $query = Employee::query();

    // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhere('number', 'like', "%$search%");
            });
        }

        // Sorting
        if ($request->has('sort_by') && $request->has('order')) {
            $query->orderBy($request->sort_by, $request->order);
        }

        return response()->json($query->get());
    }
}