# Changelog - 10-12-2026

## Modified files

- `pos.php`
  - Clear the cart, customer fields, transaction note, and payment input after a successful checkout.
  - Automatically dismiss the checkout confirmation after five seconds.
  - Add an optional customer address field to checkout and save it with the sale.
- `api/edit_sale.php`
  - Allow customer name, TIN, address, and VAT REG TIN to be edited and saved with a sale.
- `config/sale_metadata.php`
  - Add the customer TIN, customer address, and VAT REG TIN columns for existing databases.
- `api/sale_receipt.php`
  - Print customer details below the total section, with a walk-in label and blank lines when details are unavailable.
  - Use the configurable store address and receipt footer from General Settings.
  - Close the receipt window after the print dialog is dismissed and remove the Back to Sales button.
- `api/sale_details.php`
  - Include customer address in the transaction-details response.
- `sales.php`
  - Display saved customer addresses in transaction-log details.
  - Add independent Last 24 hours, 7 days, 15 days, 30 days, and 60 days filters for each Monthly report chart.
- `settings.php`
  - Add configurable receipt address and footer fields to General Settings.
  - Link to the external GitHub documentation repository.
- `includes/footer.php`
  - Update the displayed application version to `v1.0.2-10122026`.
- `reports.php`
  - Add independent Last 24 hours, 7 days, 15 days, 30 days, and 60 days range selectors to both charts in the Monthly Reports view.
- `README.md`
  - Add the external documentation repository link.
