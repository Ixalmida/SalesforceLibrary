<?php
/**
 * Salesforce API Library
 */
namespace App\Libraries;

use App\Helpers\ApplicationHelper;
use App\Models\Application;
use App\Models\Company;
use App\Models\CompanyOwner;
use App\Models\Owner;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use Illuminate\Support\Facades\Cache;

class SalesforceLibrary
{
    protected $url; // https://test.salesforce.com or https://na37.salesforce.com
    protected $token_endpoint = '/services/oauth2/token';
    protected $data_endpoint;
    protected $username;
    protected $password;
    protected $client_id;
    protected $client_secret;
    protected $client;
    protected $token;
    protected $token_time = 120; // (valid token time in minutes)
    protected $cache_time = 1440; // (data cache time in seconds)
    protected $ssl_verify = false; // (okay to connect from http)

    /**
     * Constructor
     */
    public function __construct()
    {
        // Environment (Salesforce sandbox, unless in production)
        if (empty($this->url)) {
            $this->url = env('SF_URL');
            if (env('APP_ENV') !== 'production') {
                $this->url = env('SF_SANDBOX');
            }
        }

        $this->client_id = env('SF_CLIENT_ID');
        $this->username = env('SF_USERNAME');
        $this->password = env('SF_PASSWORD');
        $this->client_id = env('SF_CLIENT_ID');
        $this->client_secret = env('SF_CLIENT_SECRET');
        $this->data_endpoint = '/services/data/' . env('SF_VERSION');

        // Sandbox username
        if (env('APP_ENV') !== 'production') {
            $this->username .= '.dev';
        }

        // Initiate client
        try {
            // Init the client
            $this->client = new Client(['base_uri' => $this->url, 'timeout' => $this->token_time, 'verify' => $this->ssl_verify, 'http_errors' => false]);
        } catch (\Exception $e) {
            \Log::debug($e->getMessage());
            return (object) [];
        }

        // Get a token
        $this->getToken();
    }

    /**
     * Put an item in cache
     *
     * @param string $key - the key name
     * @param mixed $value - the value to store
     */
    protected function cache($key, $value)
    {
        Cache::put($key, json_encode($value), $this->cache_time);
    }

    /**
     * CLear application cache
     *
     * @return void
     */
    public function clearCache()
    {
        // Flush tagged items
        Cache::flush();
    }

    /**
     * Get cached item
     *
     * @param string $key - the cache key
     * @return object - the item
     */
    protected function fromCache($key)
    {
        return (object) json_decode(Cache::get($key));
    }

    /**
     * Check for cached item
     *
     * @param string $key - the cache key
     * @param boolean
     */
    protected function inCache($key)
    {
        return Cache::has($key);
    }

    /**
     * Verify token exists
     *
     * @return boolean
     */
    public function tokenExists()
    {
        if (!empty($this->token)) {
            return true;
        }
        return false;
    }

    /**
     * Set the cache timeout
     *
     * @param integer $seconds - seconds until expiration
     * @return void
     */
    public function setCacheTime(int $seconds)
    {
        $this->cache_time = $seconds;
    }

    /**
     * Describe resource
     *
     * @param string $name - the resource name (Lead, Account, Opportunity, etc.)
     * @return array - resources
     */
    public function describeResource($name)
    {
        // Do NOT cache this data - it is very large!
        try {
            $resource = $this->getData('/sobjects/' . $name . '/describe');
        } catch (\Exception $e) {
            \Log::error('Salesforce GET failed!');
            if (env('APP_DEBUG')) {
                \Log::debug(Psr7\str($e->getRequest()));
                if ($e->hasResponse()) {
                    \Log::debug(Psr7\str($e->getResponse()));
                }
                \Log::debug($data);
            }
            return (object) [];
        }

        return $resource;
    }

    /**
     * Get field for a resource
     *
     * @param string $resource - the resource to query
     * @return object - the fields
     */
    public function getFields($resource)
    {
        // Get from cache, if available
        $key = 'sf_fields_' . strtolower($resource);
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        // Get the resource description
        $result = $this->describeResource($resource);
        if (empty($result)) {
            return $result;
        }

        // Sort the fields
        usort($result->fields, [$this, 'compareValues']);

        // Save to cache
        $this->cache($key, $result->fields);

        return $result->fields;
    }

    /**
     * Get picklists for a resource
     *
     * @param string $resource - the resource to query
     * @return object - the picklists
     */
    public function getPicklists($resource, $snakecase = true)
    {
        // Get from cache, if available
        $key = 'sf_picklist_' . strtolower($resource);
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        // Get the resource description
        $result = $this->describeResource($resource);
        if (empty($result)) {
            return $result;
        }

        // Parse picklists
        $picklist = (object) [];
        foreach ($result->fields as $field) {
            if (!empty($field->picklistValues)) {
                // Convert picklist name
                $name = $field->name;
                if ($snakecase) {
                    $name = snake_case(str_replace('_', '', str_replace('__pc', '', str_replace('__c', '', $name))));
                }
                foreach ($field->picklistValues as $item) {
                    // Skip boolean lists
                    if (in_array($item->value, ['Yes', 'No'])) {
                        continue;
                    }
                    $picklist->$name[] = $item->value;
                }
            }
        }

        // Add record types
        if (!empty($result->recordTypeInfos)) {
            foreach ($result->recordTypeInfos as $record_type) {
                $picklist->record_types[$record_type->name] = $record_type->recordTypeId;
            }
        }

        // Save to cache
        $this->cache($key, $picklist);

        return $picklist;
    }

    /**
     * Get referral sources
     *
     * @return array - matching accounts
     */
    public function getSources()
    {
        // Get from cache, if available
        $key = 'sf.sources';
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        // Get accounts
        $data = $this->runQuery("SELECT Id,Name FROM Account WHERE Account.Type <> 'Small Business Account' AND Status__c NOT IN ('Open', '') ORDER BY Name ASC");
        if (empty($data->records)) {
            return (object) [];
        }

        // Add to collection
        foreach ($data->records as $i => $record) {
            $sources[] = (object) ['id' => $record->Id, 'name' => $record->Name];
        }

        // Get more records, if any
        while (!empty($data->nextRecordsUrl)) {
            $data = $this->getNextPage($data->nextRecordsUrl);
            foreach ($data->records as $i => $record) {
                // Add to collection
                $sources[] = (object) ['id' => $record->Id, 'name' => $record->Name];
            }
        }

        // Save to cache
        $this->cache($key, $sources);

        // Save results
        return $sources;
    }

    /**
     * Get account data
     *
     * @param string $id - the account ID
     * @return object - the account
     */
    public function getAccount($id)
    {
        // Get from cache, if available
        $key = 'sf.account.' . substr($id, 0, -3);
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        // Get the account
        $account = $this->getData('/sobjects/Account/' . $id);

        // Save to cache
        $this->cache($key, $account);

        // Return the JSON decoded account
        return $account;
    }

    /**
     * Upsert an account
     *
     * @param object $company - the application company
     * @param object $application - the application
     * @return object - the account
     */
    public function upsertAccount(Application $application)
    {
        $endpoint = '/sobjects/Account';
        $company = $application->companies[0];

        // Look for existing account
        if (empty($company->sf_id) && !empty($application->applicant->sf_id)) {
            // Query applicant's account
            $response = $this->runQuery("SELECT AccountId FROM Contact WHERE Email = '" . $application->applicant->email . "'");
            if (!empty($response->records)) {
                $company->sf_id = $response->records[0]->AccountId;
                $company->save();
            }
        }

        $account = [
            'RecordTypeId' => '012U00000009R0p',
            'Name' => $company->company_name,
            'AccountSource' => $application->distributionVariant->sf_source ?? null,
            'DBA__c' => $company->dba_name,
            'Phone' => $company->phone,
            'Organization_Type__c' => $this->getAccountOrgType($company->type),
            'TIN_FEIN__c' => $company->ein_ssn,
            'Business_Start_Date__c' => $this->formatDateTime($company->getOriginal('established')),
            'NaicsCode' => $company->naics->code ?? null,
            'NaicsDesc' => $company->naics->industry ?? null,
            'BillingStreet' => $company->address1 . (empty($company->address2) ? '' : ', ' . $company->address2),
            'BillingCity' => $company->city,
            'BillingStateCode' => $company->state_abbreviation,
            'BillingPostalCode' => $company->zip,
            'Is_Home_Business__c' => $this->parseBoolean($company->home_business),
            'Rent_or_Own_Business__c' => is_null($company->own) || $company->own === '' ? null : $company->own == 1 ? 'Own' : 'Rent',
            'Is_Franchise__c' => $this->parseBoolean($company->is_franchise),
            // 'Franchise_Name__c' => isset($company->franchise->description) ? $company->franchise->description : null, // picklist doesn't match!
            'Other_Franchise_Name__c' => $company->other_franchise,
            'Years_in_Business__c' => $company->years_in_business === '' ? null : $company->years_in_business,
            'AnnualRevenue' => $company->getOriginal('revenue'),
            'NumberOfEmployees' => $company->employee_count,
            'Have_Website__c' => $this->parseBoolean($company->website),
            'Website' => $company->url,
            'Management_Resume__c' => $company->resume,
            'Business_History__c' => $company->history,
            'Export_Business_Products__c' => $this->parseBoolean($company->exporter),
            'Revenue_for_Gambiling_or_Sexual__c' => $this->parseBoolean($company->offensive),
            'Is_Lending__c' => $this->parseBoolean($company->lender),
            'Not_for_Profit__c' => $this->parseBoolean($company->non_profit),
            'Most_Recent_Tax_Filing_Year__c' => isset($company->taxes->last_filed) ? $company->taxes->last_filed : null,
            'Fiscal_Year_End__c' => isset($company->taxes->year_end) ? $company->taxes->year_end : null,
            'Company_Listed_on_Tax_Return__c' => isset($company->taxes->filed_as) ? $company->taxes->filed_as : null,
            'Is_Current_Tax_Address__c' => is_object($company->taxes) ? $this->parseNotBoolean($company->taxes->old_address) : null,
            'Online_App_Business_Confirmation_Date__c' => $this->formatDateTime($company->getOriginal('approved')),
            'Online_App_Business_Confirmation_User__c' => isset($company->approver->name) ? $company->approver->name : null,
        ];

        // Tax - old address
        if (isset($company->taxes->old_address) && $company->taxes->old_address == 1) {
            $account['Previous_Tax_Address__c'] = $company->taxes->old_address1;
            $account['Previous_Tax_City__c'] = $company->taxes->old_city;
            $account['Previous_Tax_State__c'] = $company->taxes->old_state_abbreviation;
            $account['Previous_Tax_Zip__c'] = $company->taxes->old_zip;
        }

        // Update
        if (!empty($company->sf_id)) {
            return $this->patchData($endpoint . '/' . $company->sf_id, $account);
        }

        // Account owner
        if (!empty($application->sf_owner_id)) {
            $account['OwnerId'] = $application->sf_owner_id;
        } elseif (!empty($application->distributionVariant->sf_owner) && $application->distributionVariant->sf_enabled) {
            $account['OwnerId'] = $application->distributionVariant->sf_owner;
        } elseif (!empty($application->account->sf_owner) && $application->account->sf_enabled) {
            $account['OwnerId'] = $application->account->sf_owner;
        } else {
            // will default to "Salesforce Sync" user
        }

        // Create
        return $this->postData($endpoint, $account);
    }

    /**
     * Translate an account type name to a Salesforce picklist value
     *
     * @param App\Models\CompanyType $type
     * @return string - the picklist value
     */
    protected function getAccountOrgType($type)
    {
        if (empty($type->name)) {
            return null;
        }

        switch ($type->name) {
            case 'llc':
                return 'LLC';
                break;
            case 'scorp':
                return 'S-Corp';
                break;
            default:
                return $type->description;
        }
    }

    /**
     * Get Contact
     *
     * @param string $id - the Salesforce ID
     * @return object - the contact
     */
    public function getContact($id)
    {
        // Get from cache, if available
        $key = 'sf.contact.' . $id;
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        $contact = $this->getData('/sobjects/Contact/' . $id);

        // Save to cache
        $this->cache($key, $contact);

        // Return contact
        return $contact;
    }

    /**
     * Get next page of data from endpoint
     *
     * @param string $endpoint - the endpoint to query
     * @return object - the data
     */
    public function getNextPage($endpoint)
    {
        // Ensure a token exists
        if (!$this->tokenExists()) {
            return (object) [];
        }

        // Attach token
        $data['headers'] = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
        ];

        // Make the request
        try {
            $response = $this->client->get($this->url . $endpoint, $data);
        } catch (\Exception $e) {
            \Log::error('Salesforce GET failed! URL: ' . $this->url . $this->data_endpoint . $endpoint);
            if (env('APP_DEBUG')) {
                \Log::debug(Psr7\str($e->getRequest()));
                if ($e->hasResponse()) {
                    \Log::debug(Psr7\str($e->getResponse()));
                }
                \Log::debug($data);
            }
            return (object) [];
        }

        // Check response code
        $code = $response->getStatusCode();
        if ($code >= 300) {
            // Don't log not found error
            if ($code == 404) {
                return (object) [];
            }

            \Log::error('Salesforce GET request rejected! Code:' . $code);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug($body);
            }
            return (object) [];
        }

        // Get response body
        $body = $response->getBody();

        // Get response contents
        return json_decode($body);
    }

    /**
     * Upsert a contact
     *
     * @param App\Models\CompanyOwner $owner
     * @param App\Models\Application $application
     * @return object - the contact
     */
    public function upsertContact(CompanyOwner $company_owner, Application $application)
    {
        $endpoint = '/sobjects/Contact';

        // Email required
        if (empty($company_owner->owner->email)) {
            return false;
        }

        // Look for existing contact
        if (empty($company_owner->owner->sf_id)) {
            $response = $this->runQuery("SELECT Id FROM Contact WHERE Email = '" . $company_owner->owner->email . "'");
            if (!empty($response->records)) {
                $company_owner->owner->sf_id = $response->records[0]->Id;
                $company_owner->owner->save();
            }
        }

        // Format data
        $contact = [
            'AccountId' => $application->companies[0]->sf_id,
            'LeadSource' => $application->distributionVariant->sf_source ?? null,
            'Salutation' => $company_owner->owner->honorific,
            'FirstName' => substr($company_owner->owner->name, 0, strpos($company_owner->owner->name, ' ')),
            'LastName' => substr($company_owner->owner->name, strpos($company_owner->owner->name, ' ') + 1),
            'Suffix__c' => $company_owner->owner->suffix,
            'Gender__c' => empty($company_owner->owner->gender) ? null : $company_owner->owner->gender,
            'Birthdate' => $this->formatDateTime($company_owner->owner->getOriginal('birth_date')),
            'SSN__c' => empty($company_owner->owner->ssn) ? null : $company_owner->owner->ssn,
            'MailingStreet' => $company_owner->owner->address1 . (empty($company_owner->owner->address2) ? '' : ', ' . $company_owner->owner->address2),
            'MailingCity' => $company_owner->owner->city,
            'MailingStateCode' => $company_owner->owner->state_abbreviation,
            'MailingPostalCode' => $company_owner->owner->zip,
            'Phone' => $company_owner->owner->phone,
            'Email' => $company_owner->owner->user->email ?? $company_owner->owner->email,
            'Salary__c' => $company_owner->owner->salary ?? null,
            'Company_Name__c' => $application->companies[0]->company_name ?? null,
            'Estimated_Credit_Score__c' => $company_owner->owner->rating->description ?? null,
            'Ownership_Percentage__c' => number_format($company_owner->ownership),
            'Is_Authorized_Signer__c' => $this->parseBoolean($company_owner->authorized_signer),
            'Contact_Type__c' => 'Business Owner',
            'Title' => empty($company_owner->title) ? $company_owner->type : $company_owner->title,
            'Own_or_Rent__c' => $company_owner->owner->own === '' ? null : $company_owner->owner->own == 1 ? 'Own' : 'Rent',
            'Monthly_Home_Payment__c' => empty($company_owner->owner->monthly_payment) ? null : round($company_owner->owner->monthly_payment),
            'Citizenship__c' => $company_owner->owner->citizenship->name ?? null,
            'Permanent_Resident_Alien__c' => $this->parseBoolean($company_owner->owner->resident),
            'Alien_Registration_Number__c' => $company_owner->owner->alien_registration_number,
            'Country_of_Birth__c' => isset($company_owner->owner->country->name) ? $company_owner->owner->country->name : null,
            'State_of_Birth__c' => isset($company_owner->owner->birth_state) ? $company_owner->owner->birth_state : null,
            'City_of_Birth__c' => substr($company_owner->owner->birth_city, 0, 99), // max length: 100 chars
            'Photo_Identification__c' => $company_owner->owner->idType->name ?? null,
            'Photo_ID_Number__c' => $company_owner->owner->id_number,
            'Identification_State_Issued__c' => $company_owner->owner->id_state,
            'Identification_Expiration_Date__c' => $this->formatDateTime($company_owner->owner->getOriginal('id_expires')),
            'Veteran__c' => $this->parseBoolean($company_owner->owner->veteran),
            'Race__c' => isset($company_owner->owner->race->name) ? $company_owner->owner->race->name : null,
            'Ethnicity__c' => isset($company_owner->owner->ethnicity->name) ? $company_owner->owner->ethnicity->name : null,
            'Eligibility_Indictment__c' => $this->parseBoolean($company_owner->owner->indicted),
            'Eligibility_Arrested_Past_Six_Months__c' => $this->parseBoolean($company_owner->owner->arrested),
            'Eligibility_Criminal_Charges__c' => $this->parseBoolean($company_owner->owner->convicted),
            'Eligibility_Applied_SBA__c' => $this->parseBoolean($company_owner->owner->previous_fed_loan),
            'Eligibility_Debarred__c' => $this->parseBoolean($company_owner->owner->sba_debarred),
            'Eligibility_Sixty_Days_Deliquent__c' => $this->parseBoolean($company_owner->owner->legal_delinquency),
            'Eligibility_Affiliate_Businesses__c' => $this->parseBoolean($company_owner->owner->has_affiliates),
            'Eligibility_Affiliate_Deliquent__c' => $this->parseBoolean($company_owner->owner->fed_delinquency),
            'Eligibility_Affiliate_Default__c' => $this->parseBoolean($company_owner->owner->fed_default),
            'Eligibility_SBA_Employee__c' => $this->parseBoolean($company_owner->owner->sba_employee),
            'Eligibility_Congress_or_Fed__c' => $this->parseBoolean($company_owner->owner->fed_employee),
            'Eligibility_SBA_Separated__c' => $this->parseBoolean($company_owner->owner->sba_former),
            'Eligibility_SCORE__c' => $this->parseBoolean($company_owner->owner->sbac_score),
            'Eligibility_GS13__c' => $this->parseBoolean($company_owner->owner->gs13),
            'Cash_in_Bank__c' => $company_owner->owner->cash_reserves,
            'Marketable_Securities__c' => $company_owner->owner->securities,
            'Value_of_Life_Insurance__c' => $company_owner->owner->life_insurance,
            'Retirement_Accounts__c' => $company_owner->owner->retirement,
            'Real_Estate_Owned__c' => $company_owner->owner->real_estate,
            'Other_Assets__c' => $company_owner->owner->other_assets,
            'Credit_Cards__c' => $company_owner->owner->cc_balance,
            'Installement_Loans__c' => $company_owner->owner->loan_balance,
            'Mortgages__c' => $company_owner->owner->mortgage_balance,
            'Other_Liabilities__c' => $company_owner->owner->other_balance,
            'Most_Recent_Tax_Year_Filed__c' => $company_owner->owner->tax_year,
            'Primary_Filer_Name__c' => $company_owner->owner->tax_name,
            'Primary_Filer_SSN__c' => $company_owner->owner->tax_ssn,
            'Was_it_a_Joint_Return__c' => $this->parseBoolean($company_owner->owner->joint_return),
            'Second_Filer_Name__c' => $company_owner->owner->joint_name,
            'Second_Filer_SSN__c' => $company_owner->owner->joint_ssn,
            'Different_Tax_Address__c' => empty($company_owner->owner->prev_address) ? 'No' : 'Yes',
            'Previous_Tax_Address__c' => $company_owner->owner->prev_address,
            'Previous_Tax_City__c' => $company_owner->owner->prev_city,
            'Previous_Tax_State__c' => $company_owner->owner->prev_state,
            'Previous_Tax_Zip__c' => $company_owner->owner->prev_zip,
            'Application_Date_Confirmed__c' => $this->formatDateTime($company_owner->owner->getOriginal('confirmed')),
            'Application_Confirmed_User__c' => empty($company_owner->owner->confirmed) ? null : $company_owner->owner->name,
        ];

        // Email OptOut
        if (isset($application->distributionVariant)) {
            $contact['HasOptedOutOfEmail'] = $application->distributionVariant->sf_opt_out == 1 ? true : false;
        }

        // Parse affiliate names
        if ($company_owner->owner->has_affiliates && !empty($company_owner->owner->affiliates)) {
            $contact['Affiliate_Names__c'] = implode(', ', $company_owner->owner->affiliates->pluck('name')->toArray());
        }

        // Financial totals
        if (!empty($company_owner->owner->cash_reserves)) {
            $contact['Total_Assets__c'] = intval($company_owner->owner->cash_reserves) + intval($company_owner->owner->securities) + intval($company_owner->owner->life_insurance) + intval($company_owner->owner->retirement) + intval($company_owner->owner->real_estate) + intval($company_owner->owner->other_assets);
            $contact['Total_Liabilities__c'] = intval($company_owner->owner->cc_balance) + intval($company_owner->owner->loan_balance) + intval($company_owner->owner->mortgage_balance) + intval($company_owner->owner->other_balance);
            $contact['Net_Worth__c'] = $contact['Total_Assets__c'] - $contact['Total_Liabilities__c'];
        }

        // Owner
        if (!empty($application->sf_owner_id)) {
            $contact['OwnerId'] = $application->sf_owner_id;
        } elseif (!empty($application->distributionVariant->sf_owner) && $application->distributionVariant->sf_enabled) {
            $contact['OwnerId'] = $application->distributionVariant->sf_owner;
        } elseif (!empty($application->account->sf_owner) && $application->account->sf_enabled) {
            $contact['OwnerId'] = $application->account->sf_owner;
        } else {
            // will default to "Salesforce Sync" user
        }

        // Update existing contact?
        if (!empty($company_owner->owner->sf_id)) {
            $response = $this->patchData($endpoint . '/' . $company_owner->owner->sf_id, $contact);
            return $response;
        }

        // Insert new contact
        $response = $this->postData($endpoint, $contact);
        if (isset($response->id)) {
            $company_owner->owner->sf_id = $response->id;
            $company_owner->owner->save();
            return $response;
        }
    }

    /**
     * Upsert a facility (loan)
     *
     * @param object $application - the application
     * @return object - the facility
     */
    public function upsertFacility(Application $application)
    {
        $endpoint = '/sobjects/Facility__c';

        $facility = [
            'Total_Pipeline_Amount__c' => $application->amount,
            'Term_years__c' => $application->term,
            'Base_Rate__c' => env('PRIMERATE'),
            'Rate_Spread__c' => $application->productVariant->spread ?? null,
            'APR__c' => $application->apr ?? null,
            'Status__c' => $application->status->description,
            'Stage__c' => $application->stage->description,
            'Payment_Frequency__c' => 'Monthly',
            'Estimated_Close_Date__c' => date('Y-m-d', strtotime('+2 months')), // new
            'Bank_Paid_Referral_Fee_Percent__c' => 0.0, // new
            'Packaging_Fee__c' => 0.0, // new
        ];

        // Convert product to loan type
        if (isset($application->product->name)) {
            $facility['Loan_Type__c'] = $application->product->description;
            if ($application->product->name === 'sba_7a') {
                $facility['Loan_Type__c'] = 'SBA >$350K';
            }
            if (in_array($application->product->name, ['commercial_construction', 'residential_construction'])) {
                $facility['Loan_Type__c'] = 'Conventional';
            }
        }

        // Update or create?
        if (!empty($application->sf_facility)) {
            return $this->patchData($endpoint . '/' . $application->sf_facility, $facility);
        }

        // Make sure there is an opportunity ID
        if (empty($application->sf_id)) {
            \Log::warning('Facility cannot be created for application #' . $application->id . ' because there is no opportunity ID.');
            return false;
        }

        // Only include relations on POST (cannot update relations)
        $facility['Opportunity__c'] = $application->sf_id;

        return $this->postData($endpoint, $facility);
    }

    /**
     * Set application status
     *
     * @param App\Models\Application $application
     * @return void
     */
    public function updateApplicationStatus(Application $application, $last_page)
    {
        if (!empty($application->sf_id)) {
            $data = [
                'StageName' => $application->stage->description,
                'Status__c' => $application->status->description,
                'Application_Last_Page__c' => $last_page,
                'Application_Furthest_Page__c' => $application->furthest_endpoint,
            ];
            $this->patchData('/sobjects/Opportunity/' . substr($application->sf_id, 0, -3), $data);
        }
    }

    /**
     * Set facility status
     *
     * @param App\Models\Application $application
     * @return void
     */
    public function updateFacilityStatus(Application $application)
    {
        if (!empty($application->sf_facility)) {
            $data = [
                'Stage__c' => $application->stage->description,
                'Status__c' => $application->status->description,
            ];
            $this->patchData('/sobjects/Facility__c/' . substr($application->sf_facility, 0, -3), $data);
        }
    }

    /**
     * Get the account owner
     *
     * @param string $account_id
     * @return array - the owner record
     */
    public function getAccountOwner($account_id)
    {
        // Get from cache, if available
        $key = 'sf.account.' . $account_id . '.owner';
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        // Get the account owner
        $owner = $this->getData('/sobjects/Account/' . $account_id . '/owner');

        // Save to cache
        $this->cache($key, $owner);

        // Return the JSON decoded account
        return $owner;
    }

    /**
     * Get Lead
     *
     * @param string $id - the lead ID
     * @return string - response
     */
    public function getLead($id)
    {
        // Get from cache, if available
        $key = 'sf.lead.' . $id;
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        $lead = $this->getData('/sobjects/Lead/' . $id);

        // Save to cache
        $this->cache($key, $lead);

        // Return lead
        return $lead;
    }

    /**
     * Try to convert lead
     *
     * @return boolean - converted/not converted
     */
    public function attemptLeadConversion(Application $application)
    {
        // Applicant email required
        if (empty($application->applicant->email)) {
            \Log::notice('Cannot convert Salesforce lead without an email address - application #' . $application->id);
            return false;
        }

        // Lead exists?
        $response = $this->runQuery("SELECT Id FROM Lead WHERE Email='" . $application->applicant->email . "'");
        if (empty($response->records[0]->Id)) {
            return false;
        }

        return $this->convertLead($response->records[0]->Id, $application);
    }

    /**
     * Convert Lead to Opportunity
     *
     * @param string $lead_id - the Salesforce lead ID
     * @param App\Models\Application $application
     * @return string - the Opportunity ID
     */
    public function convertLead($lead_id, Application $application)
    {
        // Get the Lead
        $lead = $this->getLead($lead_id);
        if (empty($lead->Id)) {
            \Log::notice('Lead ID ' . $lead_id . ' not found for application #' . $application->id);
            return false;
        }
        if ($lead->IsConverted) {
            \Log::notice('Lead ID ' . $lead_id . ' was already converted for application #' . $application->id);
            return false;
        }

        // Update lead with required fields (& update amount)
        if (empty($lead->Use_of_Proceeds__c) || empty($lead->Submission_Id__c)) {
            $data = (object) [
                'Use_of_Proceeds__c' => $application->usage->description ?? 'Working Capital',
                'Submission_Id__c' => $application->id,
                'Estimated_Loan_Amount__c' => $application->amount,
                'Type__c' => $application->productVariant->product->description ?? 'Celtic Express',
                'LeadSource' => !empty($lead->LeadSource) ? $lead->LeadSource : $application->distributionVariant->sf_source ?? 'Web (Organic)',
                'Owner_ID__c' => $lead->Owner_ID__c, // must re-submit, or it will default to API user
            ];
            $response = $this->patchData('/sobjects/Lead/' . $lead_id, $data);
        }

        // Convert
        $data = (object) ["leadId" => $lead_id];
        return $this->postApex('/services/apexrest/LeadConverter', $data);
    }

    /**
     * Upsert SF lead
     *
     * @param App\Models\Application $application - the loan application
     * @return string - response
     */
    public function upsertLead(Application $application, $lead_id)
    {
        // Lead endpoint
        $endpoint = '/sobjects/Lead/';

        // Format the Salesforce lead data
        $data = (object) [
            // Application data
            'Estimated_Loan_Amount__c' => str_replace(',', '', $application->amount),
            'Description' => $application->productVariant->name ?? null,
            'RecordTypeId' => $application->productVariant->product->type->sf_type ?? null,
            'Status' => 'Open',
            'Estimated_Monthly_Payment__c' => str_replace(',', '', $application->est_payment),
            'Estimated_Rate__c' => $application->rate,
            'Term_years__c' => $application->term,
            'Is_Debt_Refinance_Credit_Card__c' => $this->parseBoolean($application->credit_card),
            'Terms_and_Conditions_Acceptance__c' => $this->parseBoolean($application->t_and_c1),
            'Terms_and_Conditions_Date__c' => date('c', strtotime($application->getOriginal('t_and_c1'))),
            'Application_Last_Page__c' => $application->current_endpoint,
            'Submission_Id__c' => $application->id,
            'Use_of_Proceeds__c' => $application->usage->description ?? 'Working Capital',
            'Type__c' => $application->productVariant->product->description ?? null,
            // 'Equipment_Finance_Condition_Type__c' => ,
            // Referral source
            'LeadSource' => $application->account_id == 1 ? 'Web (Organic)' : 'Referral',
            'Is_There_a_Referral_Source__c' => $application->account_id > 1 ? 'Yes' : 'No',
            'Dealer_Name__c' => $application->account->description,
            'Dealer_Code__c' => $application->account->nova_id, // ?
            // 'Dealer_Contact_Name__c' => $application->account->contact->name,
            // 'Dealer_Contact_Phone__c' => $application->account->contact->phone,
            // 'Bank_Paid_Referral_Fee_Percent__c' => $application->account->fee_percent,
            // Owner detail
            'FirstName' => substr($application->applicant->name, 0, strpos(' ', $application->applicant->name)),
            'LastName' => substr($application->applicant->name, strpos(' ', $application->applicant->name) + 1),
            'Salutation' => $application->applicant->honorific,
            'Phone' => $application->applicant->phone,
            'Email' => $application->applicant->email,
            'Rating' => $application->applicant->rating->name,
            'Estimated_Credit_Score__c' => $application->applicant->rating->name,
            // Onwer eligibility
            'Eligibility_Arrested_Past_Six_Months__c' => $this->parseBoolean($application->applicant->arrested),
            'Eligibility_Indictment_Parole_Probation__c' => $this->parseBoolean($application->applicant->indicted),
            'Eligibility_Criminal_Offense__c' => $this->parseBoolean($application->applicant->convicted),
            'Eligibility_Defaulted_Government_Loan__c' => $this->parseBoolean($application->applicant->fed_default),
            'Eligibility_US_Citizen__c' => $application->applicant->citizenship_id === 4 ? 'Yes' : 'No',
            // Company detail
            'Company' => $application->companies[0]->company_name,
            'DBA_Name__c' => $application->companies[0]->dba_name,
            'Street' => $application->companies[0]->address1 ?? 'Unknown',
            'City' => $application->companies[0]->city ?? 'Unknown',
            'State' => $application->companies[0]->state->name ?? 'UT',
            'PostalCode' => $application->companies[0]->zip ?? '84606',
            'Country' => 'United States',
            'Company_Phone__c' => $application->companies[0]->phone,
            'Have_Website__c' => $this->parseBoolean($application->companies[0]->website) ?? 'No',
            'Website' => $application->companies[0]->url,
            'AnnualRevenue' => str_replace(',', '', $application->companies[0]->revenue),
            'NumberOfEmployees' => $application->companies[0]->employee_count,
            'Years_in_Business__c' => $application->companies[0]->years_in_business,
            'Company_Start_Date__c' => date('c', strtotime($application->companies[0]->established)),
            'Entity_Type__c' => isset($application->companies[0]->type->sf_id) ? $application->companies[0]->type->sf_id : null,
            'TIN_FEIN__c' => $application->companies[0]->ein_ssn,
            'Is_Franchise__c' => $this->parseBoolean($application->companies[0]->is_franchise),
            'Franchise_Name__c' => empty($application->companies[0]->franchise->description) ? null : $application->companies[0]->franchise->description,
            // Company eligibility
            'Eligibility_Is_Lending__c' => $this->parseBoolean($application->companies[0]->lender),
            'Eligibility_Not_For_Profit__c' => $this->parseBoolean($application->companies[0]->non_profit),
            'Eligibility_Sexual_or_Gambling__c' => $this->parseBoolean($application->companies[0]->offensive),
        ];

        // Update, if Salesforce ID is set
        if (!empty($application->sf_id)) {
            return $this->patchData($endpoint . $application->salesforce_id, $data);
        }
        return $this->postData($endpoint, $data);
    }

    /**
     * Get Opportunity
     *
     * @param App\Models\Application $application - the loan application
     * @return object - the opportunity
     */
    public function getOpportunity($id)
    {
        // Get from cache, if available
        $key = 'sf.lead.' . $id;
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        // Get opportunity
        $opportunity = $this->getData('/sobjects/Opportunity/' . $id);

        // Get owner name
        $q = $this->runQuery("SELECT Owner.Name FROM Opportunity WHERE Id='" . $id . "'");

        // Add owner name to opportunity
        if (!empty($q->records)) {
            $opportunity->OwnerName = $q->records[0]->Owner->Name;
        }

        // Save to cache
        $this->cache($key, $opportunity);

        // Return lead
        return $opportunity;
    }

    /**
     * Create Salesforce opportunity
     *
     * @param App\Models\Application $application
     * @param integer $account_id - (optional) owner account ID
     * @return object - the server response
     */
    public function upsertOpportunity(Application $application)
    {
        $endpoint = '/sobjects/Opportunity/';

        // Applicant email & SF account required
        if (empty($application->applicant->email)) {
            \Log::notice('Salesforce opportunity cannot be created due to missing applicant email - application #' . $application->id);
            return false;
        }

        if (empty($application->companies[0]->sf_id)) {
            \Log::notice('Salesforce opportunity cannot be created due to missing account ID - application #' . $application->id);
            return false;
        }

        // Format data
        $opportunity = [
            'AccountId' => $application->companies[0]->sf_id,
            'Who_Are_You__c' => $application->customerType->description,
            'Amount' => $application->amount,
            'RecordTypeId' => $application->distributionVariant->sf_record_type ?? '012U00000001pat',
            'Name' => $application->companies[0]->company_name,
            'Type' => $application->productVariant->product->description ?? null,
            'Application_Last_Page__c' => $application->current_endpoint,
            'StageName' => $application->stage->description,
            'Status__c' => $application->status->description,
            'CloseDate' => date('Y-m-d', strtotime('+2 months')),
            'Read_Terms_and_Conditions__c' => empty($application->t_and_c2) ? null : 'Yes',
            'Terms_and_Conditions_Date__c' => $this->formatDateTime($application->getOriginal('t_and_c1')),
            'Terms_and_Conditions_Acceptance__c' => empty($application->t_and_c1) ? null : 'Yes',
            'Application_Confirmed_User__c' => empty($application->t_and_c1) ? null : $application->applicant->name,
            'Finished_Application__c' => empty($application->approved) ? 0 : 1,
            'CRA_Eligible__c' => $this->parseBoolean($application->cra_qualified),
            'Is_Debt_Refinance_Credit_Card__c' => $this->parseBoolean($application->credit_card),
            // 'Loan_Application_Id__c' => $application->novatraq_id ?? null, // new
            'Submission_Id__c' => $application->id ?? null, // new
            'Closed_Lost_Reason__c' => empty($application->reject_reason) ? null : $application->reject_reason,
            'Closed_Lost_Detail__c' => empty($application->reject_details) ? null : $application->reject_details,
            'LeadSource' => $application->distributionVariant->sf_source ?? null,
            'Opportunity_Teams__c' => isset($application->distributionVariant->sfTeams) && count($application->distributionVariant->sfTeams) > 0 ? implode(';', $application->distributionVariant->sfTeams->pluck('salesforce_team_id')->toArray()) : 'UT - Direct Sales',
            'Novatraq_Tracking_Number__c' => empty($application->novatraq_id) ? null : $application->novatraq_id,
            'Employee_Id__c' => $application->employee_id,
            'All_Owners_Verified__c' => ApplicationHelper::ownersVerified($application->id),
            'Number_of_Jobs_Created__c' => $application->jobs_created,
            'Number_of_Jobs_Retained__c' => $application->jobs_retained,
            'Estimated_Total_Export_Sales__c' => $application->export_amount,
            'More_Than_10K_for_Construction__c' => $this->parseBoolean($application->construction_gt10k),
            'IP_Address__c' => $application->ip_address ?? '',
            'UA_Browser_Info__c' => $application->browser_info ?? '',
            'Refid__c' => $application->refid,
        ];

        // Campaign
        if (!empty($application->distributionVariant->campaign_id)) {
            $opportunity['CampaignId'] = $application->distributionVariant->campaign_id;
        }

        // Lead/referral source
        $opportunity['Is_There_a_Referral_Source__c'] = 'No';
        if (isset($application->account_id)) {
            // Account must be Salesforce enabled and have a Salesforce ID
            if ($application->account->sf_enabled && !empty($application->account->sf_account)) {
                $opportunity['Is_There_a_Referral_Source__c'] = 'Yes'; // new
                $opportunity['Referral_Partner__c'] = $application->account->sf_account;
            }
        }

        // Application complete?
        if (!empty($application->t_and_c2)) {
            $opportunity['Finished_Application__c'] = true;
        }

        // Lost reason & details
        if (!empty($application->reject_reason)) {
            $opportunity['Closed_Lost_Reason__c'] = $application->reject_reason;
            $opportunity['Closed_Lost_Detail__c'] = $application->reject_details;
        }

        // Update
        if (!empty($application->sf_id)) {
            return $this->patchData($endpoint . substr($application->sf_id, 0, -3), $opportunity);
        }

        // Owner
        if (!empty($application->sf_owner_id)) {
            $opportunity['OwnerId'] = $application->sf_owner_id;
        } elseif (!empty($application->distributionVariant->sf_owner) && $application->distributionVariant->sf_enabled) {
            $opportunity['OwnerId'] = $application->distributionVariant->sf_owner;
        } elseif (!empty($application->account->sf_owner) && $application->account->sf_enabled) {
            $opportunity['OwnerId'] = $application->account->sf_owner;
        } else {
            // will default to "Salesforce Sync" user
        }

        // Create
        $response = $this->postData($endpoint, $opportunity);
        if (isset($response->id)) {
            $application->sf_id = $response->id;
            $application->save();
            return $application;
        }

        // Shouldn't get here unless POST fails
        \Log::error('Opportunity creation failed for application #' . $application->id . '. No opportunity ID in response.');
        return false;
    }

    /**
     * Create broker role for opportunity
     *
     * @param App\Models\Application $application
     * @return object - the server response
     */
    public function createBrokerRole(Application $application)
    {
        // Must have an opportunity ID and account ID > 1
        if (empty($application->sf_id) || $application->account_id === 1) {
            \Log::notice('Cannot create Salesforce broker role without opportunity ID and broker account - ' . $application->id);
            return (object) [];
        }

        // Get a referral source contact
        $response = $this->runQuery("SELECT Id,Name FROM Contact WHERE AccountId='" . $application->account->sf_account . "' ORDER BY Is_Authorized_Signer__c DESC LIMIT 1");
        if (empty($response->records[0]->Id)) {
            \Log::notice('Cannot create Salesforce broker contact role for application #' . $application->id . ' because the SOQL query failed to find any contacts.');
        } else {
            // Create contact role
            $role = [
                'ContactId' => $response->records[0]->Id,
                'OpportunityId' => $application->sf_id,
                'Role' => 'Loan Broker / Referral Source',
            ];
            return $this->postData('/sobjects/OpportunityContactRole', $role);
        }
        return (object) [];
    }

    /**
     * Create owner role for opportunity
     *
     * @param App\Models\Application $application
     * @return object - the server response
     */
    public function createOwnerRole(Application $application, $owner = null)
    {
        // Must have an opportunity ID
        if (empty($application->sf_id)) {
            return (object) [];
        }

        // Create contact role
        $role = [
            'ContactId' => $owner->sf_id ?? $application->applicant->sf_id,
            'OpportunityId' => $application->sf_id,
            'Role' => 'Small Business Owner',
            'isPrimary' => !isset($owner->id) ? true : false,
        ];
        return $this->postData('/sobjects/OpportunityContactRole', $role);
        // NOTE: will never update (PATCH)
    }

    /**
     * Attach company party to opportunity
     *
     * @param App\Models\Application $application
     * @return object - the server response
     */
    public function upsertAccountParty(Application $application)
    {
        // Make sure there is an opportunity ID
        if (empty($application->sf_id)) {
            \Log::notice('Salesforce account party cannot be created for application #' . $application->id . ' because there is no opportunity ID.');
            return false;
        }

        // Simplify vars
        $company = $application->companies[0];

        // Company
        $data = [
            'RecordTypeId' => '012U0000000AD76IAG', // Business
            'Party_Role__c' => 'Borrower',
            'Company_Name__c' => $company->company_name,
            'DBA_Name__c' => $company->dba_name,
            'Address_Line_1__c' => $company->address1 ?? null,
            'Address_Line_2__c' => $company->address2 ?? null,
            'City__c' => $company->city ?? null,
            'State__c' => $company->state_abbreviation ?? null,
            'Zipcode__c' => $company->zip ?? null,
            'Is_Franchise__c' => $this->parseBoolean($company->is_franchise),
            // 'Franchise_Name__c' => $company->franchise->description ?? null, // Don't enable until franchise names match picklist
            'Franchise_Other_Name__c' => $company->other_franchise ?? null,
            'Date_Established_Company__c' => $this->formatDate($company->established) ?? null,
            'Tax_Payer_Id__c' => $company->ein_ssn ?? null,
            'NAICS__c' => $company->naics->code ?? null,
            'Nature_of_Business__c' => $company->naics->industry ?? null,
            'Organization_Type__c' => isset($company->type->name) ? $company->type->name === 'llc' ? 'LLC' : $company->type->name === 'scorp' ? 'S-Corp' : $company->type->name === 'partnership' ? 'Partnership - General' : $company->type->name === 'soleprop' ? $company->type->description : 'Other' : null,
        ];

        // Update or create
        if (!empty($company->sf_party_id)) {
            return $this->patchData('/sobjects/Party__c/' . $company->sf_party_id, $data);
        }

        $data['Opportunity__c'] = $application->sf_id;

        return $this->postData('/sobjects/Party__c', $data);
    }

    /**
     * Attach owner party to opportunity
     *
     * @param App\Models\Application $application
     * @return object - the server response
     */
    public function upsertOwnerParty(Application $application, CompanyOwner $company_owner)
    {
        // Make sure there is an opportunity ID
        if (empty($application->sf_id)) {
            \Log::notice('Salesforce owner party cannot be created for application #' . $application->id . ' because there is no opportunity ID.');
            return false;
        }

        $owner = $company_owner->owner;

        // Owner
        $data = [
            'RecordTypeId' => '012U0000000AD7BIAW', // Individual
            'Party_Role__c' => 'Owner',
            'First_Name__c' => substr($owner->name, 0, strpos($owner->name, ' ')),
            'Last_Name__c' => substr($owner->name, strpos($owner->name, ' ') + 1),
            'Gender__c' => empty($owner->gender) || $owner->gender === 'Prefer Not to Disclose' ? null : $owner->gender,
            'Primary_Phone__c' => $owner->phone,
            'Address_Line_1__c' => $owner->address1 ?? null,
            'Address_Line_2__c' => $owner->address2 ?? null,
            'City__c' => $owner->city ?? null,
            'State__c' => $owner->state_abbreviation ?? null,
            'Zipcode__c' => $owner->zip ?? null,
            'Tax_Payer_Id__c' => $owner->ssn ?? null,
            'Date_of_Birth__c' => $this->formatDate($owner->birth_date) ?? null,
            'Date_Became_Owner__c' => $this->formatDate($owner->purchase_date) ?? null,
            // 'Title__c' => $company_owner->type ?? null, // Don't enable until titles match
            'Own_or_Rent__c' => $owner->own === null ? null : $owner->own == 1 ? 'Own' : 'Rent',
            'Amount_Monthly_Mortgage_Rent__c' => $owner->monthly_payment ?? null,
            'Ownership_Percentage__c' => empty($company_owner->ownership) ? null : round($company_owner->ownership),
        ];

        // Update or create
        if (!empty($company_owner->sf_party_id)) {
            return $this->patchData('/sobjects/Party__c/' . $company_owner->sf_party_id, $data);
        }
        $data['Opportunity__c'] = $application->sf_id;
        return $this->postData('/sobjects/Party__c', $data);
    }

    /**
     * Upsert a usage
     *
     * @param string $opportunity_id
     * @return object - the response
     */
    public function upsertUsage($application)
    {
        // Make sure there is an opportunity ID
        if (empty($application->sf_id) || empty($application->sf_facility)) {
            \Log::notice('Salesforce usage cannot be created for application #' . $application->id . ' because there is either no opportunity ID or facility ID.');
            return false;
        }

        $usage = [
            'Project_Cost__c' => $this->getSfUsage($application->usage->description),
            'Amount__c' => $application->amount,
        ];

        if (!empty($application->sf_usage)) {
            $this->patchData('/sobjects/Source_Use__c/' . substr($application->sf_usage, 0, -3), $usage);
            return (object) ['id' => $application->sf_usage];
        }

        // Only include relations on POST (cannot update relations)
        $usage['Opportunity__c'] = $application->sf_id;
        $usage['Facility__c'] = $application->sf_facility;

        return $this->postData('/sobjects/Source_Use__c/', $usage);
    }

    /**
     * Upsert usage detail
     *
     * @param \App\Models\Application $application
     * @return object - the API response
     */
    public function upsertUsageDetail($application)
    {
        $usages = [
            'debt_refinance_amount' => 'Debt Refi - SBA Loan',
            'mortgage_refinance_amount',
            'mortgage_amount' => 'Real Estate - Existing',
            'acquisition_amount',
            'inventory_amount',
            'hiring_amount',
            'marketing_amount',
            'operations_amount',
        ];
    }

    /**
     * Get Salesforce usage name
     *
     * @param string $name - use of proceeds
     * @return string - the SF usage name
     */
    protected function getSfUsage($name)
    {
        switch ($name) {
            case 'Purchasing Real Estate':
                return 'Real Estate - Existing';
                break;
            case 'Refinancing Debt':
                return 'Debt Refi - SBA Loan';
                break;
            case 'Buying Equipment':
                return 'Equipment Purchase';
                break;
            case 'Leasing Equipment':
                return 'Equipment Lease';
                break;
            case 'Building a Home':
                return 'Real Estate - Ground Up Construction';
                break;
            case 'Home Remodel':
                return 'Real Estate - Existing';
                break;
            case 'Purchasing Land/Lot':
                return 'Land';
                break;
            case 'Pre-sold Construction':
            case 'Speculative Construction':
                return 'Real Estate - Ground Up Construction';
                break;
            default:
                return $name;
        }
    }

    /**
     * Get opportunity record type from loan type name
     *
     * @param string $name - the loan type name
     * @return string - the Salesforce ID
     */
    protected function getOpportunityRecordType($name)
    {
        if ($name === 'sba_small') {
            return '012U00000001patIAA';
        }
        if ($name === 'sba') {
            return '012U00000009OgeIAE';
        }
        if ($name === 'conventional') {
            return '012U00000001qP6IAI';
        }
        if ($name === 'abl') {
            // Asset-based loan (unused)
            return '012U0000000N2VxIAK';
        }
    }

    /**
     * Get Users
     *
     * @return array - w/ID, name, email
     */
    public function getUsers()
    {
        // Get from cache, if available
        $key = 'sf.users';
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        // grab users
        $result = $this->runQuery('SELECT Id,Name,Email FROM User ORDER BY Name ASC');
        $users = [];
        if (isset($result->records)) {
            foreach ($result->records as $user) {
                $users[] = (object) [
                    'id' => $user->Id,
                    'name' => $user->Name,
                    'email' => $user->Email,
                ];
            }
        }

        // grab queues
        $result2 = $this->runQuery("SELECT Id,Name,Email FROM Group WHERE Type = 'Queue' ORDER BY Name ASC");
        if (isset($result2->records)) {
            foreach ($result2->records as $queue) {
                $users[] = (object) [
                    'id' => $queue->Id,
                    'name' => $queue->Name,
                    'email' => $queue->Email,
                ];
            }
        }

        usort($users, [$this, 'compareValues']);

        // Save to cache
        $this->cache($key, $users);

        return (object) $users;
    }

    /**
     * Get BDO users
     *
     * @return array
     */
    public function getBDOs()
    {
        // Get from cache, if available
        $key = 'sf.bdos';
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        $bdos = $this->runQuery("SELECT Id,Name,Email FROM User WHERE ProfileId IN('00eU0000000JMMSIA4', '00eU0000000rGFhIAM') ORDER BY Name ASC");

        // Save to cache
        $this->cache($key, $bdos->records);

        return (object) $bdos->records;
    }

    /**
     * Get Campaigns
     *
     * @return array - w/ID, name, email
     */
    public function getCampaigns()
    {
        // Get from cache, if available
        $key = 'sf.campaigns';
        if ($this->inCache($key)) {
            return $this->fromCache($key);
        }

        $result = $this->runQuery('SELECT Id,Name FROM Campaign ORDER BY Name ASC');
        $campaigns = [];
        foreach ($result->records as $campaign) {
            $campaigns[] = (object) [
                'id' => $campaign->Id,
                'name' => $campaign->Name,
            ];
        }

        // Save to cache
        $this->cache($key, $campaigns);

        return (object) $campaigns;
    }

    /**
     * Run SoQL query
     *
     * @param string $query - the query to run
     * @return array - results
     */
    public function runQuery($query)
    {
        // URL encode the query
        $query = urlencode($query);

        // Allow commas and single-quotes in the SOQL query
        $endpoint = '/query/?q=' . str_replace('%27', "'", str_replace('%2C', ',', $query));

        // Get/return the data
        return $this->getData($endpoint);
    }

    /**
     * Get Salesforce token
     *
     * @return void
     */
    protected function getToken()
    {
        // Set headers, if any
        // $data['headers'] = [
        // ];

        // Request body
        $data['form_params'] = [
            'grant_type' => 'password',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'username' => $this->username,
            'password' => $this->password,
        ];

        // Make the post
        try {
            $response = $this->client->post($this->url . $this->token_endpoint, $data);
        } catch (\Exception $e) {
            \Log::error('Salesforce token request failed!');
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug(Psr7\str($e->getRequest()));
                if ($e->hasResponse()) {
                    \Log::debug(Psr7\str($e->getResponse()));
                }
            }
            return (object) [];
        }

        // Check response code
        $code = $response->getStatusCode();
        if ($code >= 300) {
            \Log::error('Salesforce token request rejected! Code:' . $code);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug($response->getBody());
            }
            return (object) [];
        }

        // Get response body
        $body = json_decode($response->getBody()->getContents());

        // Update the URL
        $this->url = $body->instance_url;

        $this->token = $body->access_token;
    }

    /**
     * Get data from Salesforce
     *
     * @param string $endpoint - the endpoint to query
     * @return object - the data
     */
    protected function getData($endpoint)
    {
        // Ensure a token exists
        if (!$this->tokenExists()) {
            return (object) [];
        }

        // Attach token
        $data['headers'] = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
        ];

        // Make the request
        try {
            $response = $this->client->get($this->url . $this->data_endpoint . $endpoint, $data);
        } catch (\Exception $e) {
            \Log::error('Salesforce GET failed! URL: ' . $this->url . $this->data_endpoint . $endpoint);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug(Psr7\str($e->getRequest()));
                if ($e->hasResponse()) {
                    \Log::debug(Psr7\str($e->getResponse()));
                }
                \Log::debug($data);
            }
            return (object) [];
        }

        // Check response code
        $code = $response->getStatusCode();
        if ($code >= 300) {
            // Don't log not found error
            if ($code == 404) {
                return (object) [];
            }

            \Log::error('Salesforce GET request rejected! Code:' . $code);

            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug($response->getBody());
            }
            return (object) [];
        }

        // Get response body
        $body = $response->getBody();

        // Get response contents
        return json_decode($body);
    }

    /**
     * Post data to Salesforce
     *
     * @param string $endpoint - the URL endpoint
     * @param array $data - the data
     * @return object - the return data
     */
    protected function postData($endpoint, $data)
    {
        // Ensure a token exists
        if (!$this->tokenExists()) {
            return (object) [];
        }

        // Set headers
        $post['headers'] = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];

        // Body of request w/credentials.
        $post['json'] = $data;

        // Make the post
        try {
            $response = $this->client->post($this->url . $this->data_endpoint . $endpoint, $post);
        } catch (\Exception $e) {
            \Log::error('Salesforce POST failed! URL: ' . $this->url . $this->data_endpoint . $endpoint);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug(Psr7\str($e->getRequest()));
                if ($e->hasResponse()) {
                    \Log::debug(Psr7\str($e->getResponse()));
                }
            }
            return (object) [];
        }

        // Check response code
        $code = $response->getStatusCode();
        if ($code >= 300) {
            \Log::error('Salesforce POST request rejected! Code:' . $code . ' at endpoint: ' . $endpoint . '.');
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug($response->getBody());
                \Log::debug($data);
            }
            return (object) [];
        }

        // Get response body
        $body = $response->getBody();

        // Return decoded JSON
        return json_decode($body);
    }

    /**
     * Post to APEX endpoint
     *
     * @param string $endpoint - the URL endpoint
     * @param array $data - the data
     * @return object - the return data
     */
    protected function postApex($endpoint, $data)
    {
        // Ensure a token exists
        if (!$this->tokenExists()) {
            return (object) [];
        }

        // Set headers
        $post['headers'] = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];

        // Body of request w/credentials.
        $post['json'] = $data;

        // Make the post
        try {
            $response = $this->client->post($this->url . $endpoint, $post);
        } catch (\Exception $e) {
            \Log::error('Salesforce Apex POST failed! URL: ' . $this->url . $this->data_endpoint . $endpoint);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug(Psr7\str($e->getRequest()));
                if ($e->hasResponse()) {
                    \Log::debug(Psr7\str($e->getResponse()));
                }
            }
            return (object) [];
        }

        // Check response code
        $code = $response->getStatusCode();
        if ($code >= 300) {
            \Log::error('Salesforce POST request rejected! Code:' . $code);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug($response->getBody());
            }
            return (object) [];
        }

        // Get response body
        $body = $response->getBody();
        \Log::info('Salesforce lead converted: ' . $endpoint . ' - request body follows:');
        \Log::info($body);

        // Return decoded JSON
        return json_decode($body);
    }

    /**
     * Create Salesforce record
     *
     * @param string $endpoint - the URL endpoint
     * @param array $data - the data
     * @return object - the return data
     */
    protected function putData($endpoint, $data)
    {
        // Ensure a token exists
        if (!$this->tokenExists()) {
            return (object) [];
        }

        // Set headers
        $post['headers'] = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];

        // Body of request w/credentials
        $post['json'] = $data;

        // Make the post
        try {
            $response = $this->client->put($this->url . $this->data_endpoint . $endpoint, $post);
        } catch (\Exception $e) {
            \Log::error('Salesforce PUT failed! URL: ' . $this->url . $this->data_endpoint . $endpoint);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug(Psr7\str($e->getRequest()));
                if ($e->hasResponse()) {
                    \Log::debug(Psr7\str($e->getResponse()));
                }
            }
            return (object) [];
        }

        // Check response code
        $code = $response->getStatusCode();
        if ($code >= 300) {
            \Log::error('Salesforce PUT request rejected! Code:' . $code);

            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug($response->getBody());
            }
            return (object) [];
        }

        // Get response body
        $body = $response->getBody();

        // Get response contents
        return json_decode($body);
    }

    /**
     * Update Salesforce record
     *
     * @param string $endpoint - the URL endpoint
     * @param array $data - the data
     * @return object - the return data
     */
    protected function patchData($endpoint, $data)
    {
        // Ensure a token exists
        if (!$this->tokenExists()) {
            return (object) [];
        }

        // Set headers
        $post['headers'] = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];

        // Body of request w/credentials.
        $post['json'] = $data;

        // Make the post
        try {
            $response = $this->client->patch($this->url . $this->data_endpoint . $endpoint, $post);
        } catch (\Exception $e) {
            \Log::error('Salesforce PATCH failed! URL: ' . $this->url . $this->data_endpoint . $endpoint);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug(Psr7\str($e->getRequest()));
                if ($e->hasResponse()) {
                    \Log::debug(Psr7\str($e->getResponse()));
                }
            }
            return (object) [];
        }

        // Check response code
        $code = $response->getStatusCode();
        if ($code >= 300) {
            \Log::error('Salesforce PATCH request rejected! Code:' . $code);
            // Debug
            if (env('APP_DEBUG')) {
                \Log::debug($response->getBody());
            }
            return false;
        }

        // Some patch requests will return a 204 (no content)
        if ($code === 204) {
            return (object) [
                'endpoint' => $endpoint,
                'code' => 204,
                'method' => 'PATCH',
                'result' => 'Success! (no content)',
            ];
        }

        // Get response body
        $body = $response->getBody();

        // Get response contents
        return json_decode($body);
    }

    /**
     * Parse boolean value into null/yes/no
     *
     * @param boolean $value - the boolean value
     * @return string - the picklist string
     */
    protected function parseBoolean($value)
    {
        if ($value === 1 || $value === '1') {
            return 'Yes';
        }
        if ($value === 0 || $value === '0') {
            return 'No';
        }
        if ($value === 2 || $value === '2') {
            return 'Prefer Not to Disclose';
        }
        return null;
    }

    /**
     * Parse boolean with opposite results (true === no/false === yes)
     *
     * @param boolean $value - the boolean value
     * @return string - the picklist string
     */
    protected function parseNotBoolean($value)
    {
        if ($value === 1 || $value === '1') {
            return 'No';
        }
        if ($value === 0 || $value === '0') {
            return 'Yes';
        }
        return null;
    }

    /**
     * Sorting callback for sorting object arrays by the "name" attribute
     *
     * @example usort($object_array, [$this, 'compareValues']);
     */
    protected function compareValues($a, $b)
    {
        if (!isset($a->name, $b->name)) {
            return 0;
        }
        return strcmp($a->name, $b->name);
    }

    /**
     * Convert local date to Salesforce UTC date
     *
     * @param string $date - the date string
     * @return string - the Salesforce date
     */
    protected function formatDate($date)
    {
        if (empty($date)) {
            return null;
        }

        $dt = \Carbon\Carbon::parse($date);
        return $dt->toDateString();
    }

    /**
     * Convert local date to Salesforce UTC datetime
     *
     * @param string $date - the date string
     * @return string - the Salesforce datetime
     */
    protected function formatDateTime($date)
    {
        if (empty($date)) {
            return null;
        }

        $dt = \Carbon\Carbon::parse(str_replace('-', '', $date));
        $dt->hour = 7; // Salesforce automatically subtracts 7 hours
        return $dt->toIso8601String();
    }
}
