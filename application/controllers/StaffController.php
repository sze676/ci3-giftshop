<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class StaffController extends CI_Controller {

	// Loads the models, upload library and rule set every staff action needs.
	// Acts as the access control: non-staff roles are bounced to login.
	public function __construct()
	{
		parent::__construct();
		$this->load->model('User_model');
		$this->load->model('Product_model');
		$this->load->model('Category_model');
		$this->load->model('Reservation_model');
		$this->load->library('upload');
		$this->config->load('form_rules');

		if ( ! $this->User_model->is_staff($this->session->userdata('role'))) {
			redirect('auth/login');
		}
	}

	// Landing page for staff: total, pending and completed counts plus recent rows.
	// Everything is precomputed in the model so the view only loops and formats.
	public function dashboard()
	{
		$data = array(
			'title' => 'Dashboard - Staff',
			'active' => 'dashboard',
			'total' => $this->Product_model->get_counts(),
			'pending' => $this->Reservation_model->count_pending(),
			'completed' => $this->Reservation_model->count_completed(),
			'recent' => $this->Reservation_model->get_recent(5)
		);

		$this->load->view('templates/staff_header', $data);
		$this->load->view('staff/dashboard', $data);
		$this->load->view('templates/footer', $data);
	}

	// Product table for staff, filtered by the `filter` query string.
	// The filter is whitelisted to active/low/out here, defaulting to all.
	public function inventory()
	{
		$filter = $this->input->get('filter', TRUE);

		$data = array(
			'title' => 'Inventory - Staff',
			'active' => 'inventory',
			'filter' => in_array($filter, array('active', 'low', 'out'), TRUE) ? $filter : 'all',
			'products' => $this->Product_model->get_by_filter($filter),
			'counts' => $this->Product_model->get_counts(),
			'categories' => $this->Category_model->get_all(),
			'message' => $this->session->flashdata('message')
		);

		$this->load->view('templates/staff_header', $data);
		$this->load->view('staff/inventory', $data);
		$this->load->view('templates/footer', $data);
	}

	/**
	 * Creates a product from the inventory form.
	 *
	 * Runs the shared `giftshop_product` rule set, rejects a duplicate SKU
	 * (via `sku_exists`), and saves the uploaded image before inserting.
	 * Every failure path flashes a reason and redirects back to the
	 * inventory list, so the view is never reached on a bad POST.
	 *
	 * @return void
	 */
	public function add_product()
	{
		if ($this->input->post()) {
			$this->form_validation->set_rules($this->config->item('giftshop_product'));
			$this->form_validation->set_error_delimiters('', '');

			if ($this->form_validation->run() === TRUE) {
				$sku = $this->input->post('sku', TRUE);
				$status = $this->input->post('status', TRUE);

				if ($sku && $this->Product_model->sku_exists($sku)) {
					$this->session->set_flashdata('message', "Error: SKU '$sku' already exists!");
					redirect('staff/inventory');
				}

				$upload = $this->_upload_product_image('product_' . time() . '_');

				if ($upload['error'] !== NULL) {
					$this->session->set_flashdata('message', $upload['error']);
					redirect('staff/inventory');
				}

				$product = array(
					'name' => $this->input->post('name', TRUE),
					'category_id' => (int) $this->input->post('category_id'),
					'description' => $this->input->post('description', TRUE),
					'price' => $this->input->post('price'),
					'stock_quantity' => (int) $this->input->post('stock_quantity'),
					'sku' => $sku,
					'size' => $this->input->post('size', TRUE),
					'color' => $this->input->post('color', TRUE),
					'status' => $status,
					'low_stock_threshold' => $this->input->post('low_stock_threshold', TRUE) !== '' ? (int) $this->input->post('low_stock_threshold', TRUE) : 10,
					'image_url' => $upload['image_url']
				);

				$this->Product_model->add($product);
				$this->session->set_flashdata('message', 'Product Added Successfully!');
				redirect('staff/inventory');
			}

			$this->session->set_flashdata('message', validation_errors() ?: 'Please correct the highlighted fields.');
			redirect('staff/inventory');
		}

		redirect('staff/inventory');
	}

	/**
	 * Renders the edit form for a product and applies the update POST.
	 *
	 * A missing id is a hard 404 via `show_error`. On submit the same
	 * rule set runs with price/markup validation. Markup is a one-time
	 * adjustment to the base price; only the final selling price is stored.
	 * Errors re-render the form so entered prices and percentages are kept.
	 * The SKU check excludes this product's own id, and a
	 * new image is optional: `image_url` is only overwritten when an
	 * upload actually succeeded, otherwise the existing path is kept.
	 *
	 * @param  int    $id Product id from the URL.
	 * @return void
	 */
	public function edit_product($id)
	{
		$this->load->helper('pricing');
		$product = $this->Product_model->get_by_id((int) $id, FALSE);

		if ( ! $product) {
			show_error('Product not found.');
		}

		$error = NULL;
		if ($this->input->post('update')) {
			$rules = $this->config->item('giftshop_product');
			foreach ($rules as &$rule) {
				if ($rule['field'] === 'price') {
					$rule['label'] = 'Base Price';
					$rule['rules'] = 'required|trim|regex_match[/^[0-9]{1,8}(?:[.][0-9]{1,2})?$/D]';
					$rule['errors'] = array('regex_match' => 'Base Price must be nonnegative with up to two decimal places and at most ₱99,999,999.99.');
				}
			}
			unset($rule);
			$rules[] = array(
				'field' => 'markup_percent',
				'label' => 'Markup',
				'rules' => 'trim|regex_match[/^[0-9]{1,8}(?:[.][0-9]{1,2})?$/D]',
				'errors' => array('regex_match' => 'Markup must be a nonnegative percentage with up to two decimal places.')
			);
			$this->form_validation->set_rules($rules);

			$posted_price = $this->input->post('price');
			$posted_markup = $this->input->post('markup_percent');
			if (($posted_price !== NULL && ! is_string($posted_price)) || ($posted_markup !== NULL && ! is_string($posted_markup))) {
				$error = 'Base Price and Markup must each be a single numeric value.';
			} elseif ($this->form_validation->run() === FALSE) {
				$error = implode(' ', $this->form_validation->error_array());
			} else {
				$markup = $this->form_validation->set_value('markup_percent');
				$total_price = giftshop_price_with_markup($this->form_validation->set_value('price'), $markup === '' ? '0' : $markup);
				$sku = $this->input->post('sku', TRUE);

				if ($total_price === NULL) {
					$error = 'The selling price after markup cannot exceed ₱99,999,999.99.';
				} elseif ($sku && $this->Product_model->sku_exists($sku, (int) $id)) {
					$error = "Error: SKU '$sku' already exists!";
				} else {
					$upload = $this->_upload_product_image('product_' . time() . '_');
					if ($upload['error'] !== NULL) {
						$error = $upload['error'];
					} else {
						$fields = array(
							'name' => $this->input->post('name', TRUE),
							'category_id' => (int) $this->input->post('category_id'),
							'description' => $this->input->post('description', TRUE),
							'price' => $total_price,
							'stock_quantity' => (int) $this->input->post('stock_quantity'),
							'sku' => $sku,
							'size' => $this->input->post('size', TRUE),
							'color' => $this->input->post('color', TRUE),
							'status' => $this->input->post('status', TRUE),
							'low_stock_threshold' => $this->input->post('low_stock_threshold', TRUE) !== '' ? (int) $this->input->post('low_stock_threshold', TRUE) : 10
						);
						if ($upload['image_url'] !== '') {
							$fields['image_url'] = $upload['image_url'];
						}

						$this->Product_model->update((int) $id, $fields);
						$this->session->set_flashdata('message', 'Product Updated Successfully');
						redirect('staff/inventory');
					}
				}
			}
		}

		$data = array(
			'title' => 'Edit Product',
			'active' => 'inventory',
			'error' => $error,
			'product' => $product,
			'categories' => $this->Category_model->get_all()
		);

		$this->load->view('templates/staff_header', $data);
		$this->load->view('staff/edit_product', $data);
		$this->load->view('templates/footer', $data);
	}

	// Deletes a product by id and flashes a confirmation for the inventory list.
	// Unguarded beyond the constructor's staff check; no confirmation step.
	public function delete_product($id)
	{
		$this->Product_model->delete((int) $id);
		$this->session->set_flashdata('message', 'Product Deleted');
		redirect('staff/inventory');
	}

	// Reservation queue for staff, filtered by status via the URL segment.
	// Also returns per-status counts so the view can render the filter tabs.
	public function reservations($filter = 'all')
	{
		if ( ! in_array($filter, array_merge(array('all'), Reservation_model::STATUSES), TRUE)) {
			$filter = 'all';
		}

		$data = array(
			'title' => 'Reservations - Staff',
			'active' => 'reservations',
			'filter' => $filter,
			'counts' => $this->Reservation_model->get_status_counts(),
			'reservations' => $this->Reservation_model->get_all($filter),
			'res_message' => $this->session->flashdata('res_message')
		);

		$this->load->view('templates/staff_header', $data);
		$this->load->view('staff/reservations', $data);
		$this->load->view('templates/footer', $data);
	}

	// Read-only detail view of one reservation with its items and customer.
	// Items are fetched separately so the header row is not repeated per line.
	public function view_reservation($id)
	{
		$res = $this->Reservation_model->get_with_user((int) $id);

		if ( ! $res) {
			show_error('Reservation not found.');
		}

		$data = array(
			'title' => 'View Reservation',
			'active' => 'reservations',
			'res' => $res,
			'id' => (int) $id,
			'items' => $this->Reservation_model->get_items((int) $id)
		);

		$this->load->view('templates/staff_header', $data);
		$this->load->view('staff/view_reservation', $data);
		$this->load->view('templates/footer', $data);
	}

	/**
	 * Applies a status change posted from the reservation edit screen.
	 *
	 * The new status is checked against `Reservation_model::STATUSES`
	 * before it is written, and the resulting message is HTML so the view
	 * can bold the new status. Runs before the record is loaded, since a
	 * redirect always follows a valid transition.
	 *
	 * @param  int $id Reservation id from the URL.
	 * @return void
	 */
	public function edit_reservation($id)
	{
		if ($this->input->post('update_status')) {
			$new_status = $this->input->post('new_status', TRUE);
			if (in_array($new_status, Reservation_model::STATUSES, TRUE)) {
				$this->Reservation_model->update_status((int) $id, $new_status);
				$this->session->set_flashdata('res_message', 'Reservation status updated to <strong>' . ucfirst($new_status) . '</strong>.');
			}
			redirect('staff/reservations');
		}

		$res = $this->Reservation_model->get_with_user((int) $id);

		if ( ! $res) {
			show_error('Reservation not found.');
		}

		$data = array(
			'title' => 'Edit Reservation',
			'active' => 'reservations',
			'res' => $res
		);

		$this->load->view('templates/staff_header', $data);
		$this->load->view('staff/edit_reservation', $data);
		$this->load->view('templates/footer', $data);
	}

	/**
	 * Renders the staff reports page: revenue, trends and stock levels.
	 *
	 * Pulls six months of reservation history and a stock summary, then
	 * splits the monthly rows into separate month/count/revenue series so
	 * the chart view does not have to reshape them.
	 *
	 * @return void
	 */
	public function reports()
	{
		$monthly = $this->Reservation_model->monthly(6);
		$stock = $this->Reservation_model->stock_summary();

		$data = array(
			'title' => 'Reports - Staff',
			'active' => 'reports',
			'total_revenue' => $this->Reservation_model->total_revenue('completed'),
			'pending_revenue' => $this->Reservation_model->total_revenue('pending'),
			'total_res' => $this->Reservation_model->total_count(),
			'top_products' => $this->Reservation_model->top_products(5),
			'months' => $monthly['months'],
			'monthly_counts' => $monthly['counts'],
			'monthly_revenue' => $monthly['revenue'],
			'stock_ok' => $stock['ok'],
			'stock_low' => $stock['low'],
			'stock_out' => $stock['out']
		);

		$this->load->view('templates/staff_header', $data);
		$this->load->view('staff/reports', $data);
		$this->load->view('templates/footer', $data);
	}

	/* ------------------------------------------------------------------ */
	/*  Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Saves `product_image` into `product-images/` and returns its path.
	 *
	 * Never fails hard: a missing upload returns an empty path with a NULL
	 * error so callers can treat the image as optional. The directory is
	 * created on demand, the original name is sanitised to
	 * `[a-zA-Z0-9._-]` and prefixed to avoid collisions, and anything the
	 * Upload library rejects comes back as a stripped error string.
	 *
	 * @param  string $prefix Filename prefix, normally `product_<time>_`.
	 * @return array  ['image_url' => string, 'error' => string|NULL]
	 */
	private function _upload_product_image($prefix)
	{
		if (empty($_FILES['product_image']['name'])) {
			return array('image_url' => '', 'error' => NULL);
		}

		$dir = FCPATH . 'product-images/';
		if ( ! is_dir($dir)) {
			mkdir($dir, 0755, TRUE);
		}

		$original = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['product_image']['name']));

		$this->upload->initialize(array(
			'upload_path' => $dir,
			'allowed_types' => 'gif|jpg|jpeg|png|webp',
			'max_size' => 5120,
			'file_name' => $prefix . $original,
			'overwrite' => FALSE
		));

		if ($this->upload->do_upload('product_image')) {
			$fdata = $this->upload->data();
			return array('image_url' => 'product-images/' . $fdata['file_name'], 'error' => NULL);
		}

		return array('image_url' => '', 'error' => 'Error: ' . strip_tags($this->upload->display_errors()));
	}
}
